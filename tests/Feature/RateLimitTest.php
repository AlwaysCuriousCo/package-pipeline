<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\AuthenticationSource;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Token;
use App\Models\User;
use App\Support\NewToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ceilings on the surfaces a stranger can reach: failed Composer
 * authentication, webhook deliveries and the SSO round trip.
 *
 * The load-bearing test here is the one proving that authenticated Composer
 * traffic is never throttled. One `composer install` fans out a request per
 * package and a CI fleet arrives from a single egress address, so a limiter
 * that counted successes would break real installs — a worse outcome than the
 * guessing these limits exist to bound.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Comfortably past every budget defined in the app, so no test has to
     * restate a limit to prove it is enforced.
     */
    private const PAST_ANY_BUDGET = 80;

    protected function setUp(): void
    {
        parent::setUp();

        $private = Repository::factory()->create(['path' => 'internal', 'public' => false]);

        Package::factory()
            ->create(['name' => 'acme/widgets', 'repository_id' => $private->id])
            ->versions()->create([
                'version' => 'v1.0.0',
                'reference' => str_repeat('a', 40),
                'is_dev' => false,
                'metadata' => ['name' => 'acme/widgets', 'version' => 'v1.0.0'],
            ]);
    }

    /**
     * @param  list<TokenAbility>  $abilities
     */
    private function issueToken(array $abilities = [TokenAbility::RepositoryRead]): NewToken
    {
        return Token::issue(User::factory()->superAdmin()->create(), 'ci', $abilities);
    }

    private function guessOften(): void
    {
        for ($attempt = 1; $attempt <= self::PAST_ANY_BUDGET; $attempt++) {
            $this->withBasicAuth('token', 'pp_not-a-real-token')->getJson('/r/internal/list.json');
        }
    }

    public function test_repeated_failed_token_attempts_are_eventually_throttled(): void
    {
        // The first bad credential is an ordinary rejection...
        $this->withBasicAuth('token', 'pp_not-a-real-token')
            ->getJson('/r/internal/list.json')
            ->assertUnauthorized();

        $this->guessOften();

        // ...but guessing is not free forever.
        $response = $this->withBasicAuth('token', 'pp_not-a-real-token')
            ->getJson('/r/internal/list.json')
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        // A Composer client must not read this as a credential problem and
        // start prompting for a new token.
        $response->assertHeaderMissing('WWW-Authenticate');
        $this->assertStringContainsString('rate limit', (string) $response->json('message'));
    }

    public function test_successful_composer_traffic_is_not_throttled(): void
    {
        $new = $this->issueToken();

        // Far more requests than a real `composer install` of a handful of
        // packages, all from one address and all authenticated.
        for ($request = 1; $request <= 200; $request++) {
            $this->withToken($new->plainText)
                ->getJson('/r/internal/p2/acme/widgets.json')
                ->assertOk();
        }
    }

    public function test_a_working_token_still_authenticates_after_the_address_burns_its_failure_budget(): void
    {
        $this->guessOften();

        $this->withBasicAuth('token', 'pp_not-a-real-token')
            ->getJson('/r/internal/list.json')
            ->assertStatus(429);

        // The CI job sharing that egress address never presented a bad
        // credential, and is owed nothing but its packages.
        $this->withToken($this->issueToken()->plainText)
            ->getJson('/r/internal/list.json')
            ->assertOk();
    }

    public function test_uploads_are_throttled_per_credential_rather_than_per_address(): void
    {
        $write = [TokenAbility::RepositoryRead, TokenAbility::RepositoryWrite];

        $first = $this->issueToken($write)->plainText;

        for ($upload = 1; $upload <= self::PAST_ANY_BUDGET; $upload++) {
            $this->withToken($first)->postJson('/upload/acme/widgets');
        }

        $this->withToken($first)->postJson('/upload/acme/widgets')->assertStatus(429);

        // A second machine behind the same egress address carries its own
        // budget: this reaches the controller and fails on the missing file,
        // which is the whole difference between keying on the credential and
        // keying on the address.
        $this->withToken($this->issueToken($write)->plainText)
            ->postJson('/upload/acme/widgets')
            ->assertUnprocessable();
    }

    public function test_a_noisy_repositorys_webhook_does_not_starve_another(): void
    {
        $noisy = Package::factory()->create(['name' => 'acme/noisy', 'webhook_secret' => 'shh']);
        $quiet = Package::factory()->create(['name' => 'acme/quiet', 'webhook_secret' => 'shh']);

        for ($delivery = 1; $delivery <= self::PAST_ANY_BUDGET; $delivery++) {
            $this->postJson(route('webhooks.github.package', $noisy), []);
        }

        $this->postJson(route('webhooks.github.package', $noisy), [])->assertStatus(429);

        // Unsigned, so still refused — but refused on its signature, which is
        // the proof it was heard at all.
        $this->postJson(route('webhooks.github.package', $quiet), [])->assertUnauthorized();
    }

    public function test_sso_callbacks_are_throttled(): void
    {
        // Dormant, so the round trip stops before any outbound request; the
        // limiter counts the attempt either way.
        $source = AuthenticationSource::factory()->create(['active' => false]);

        $this->get(route('sso.callback', $source))->assertNotFound();

        for ($attempt = 1; $attempt <= self::PAST_ANY_BUDGET; $attempt++) {
            $this->get(route('sso.callback', $source));
        }

        $this->get(route('sso.callback', $source))->assertStatus(429);
    }
}
