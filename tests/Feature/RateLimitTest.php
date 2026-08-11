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
use Illuminate\Support\Str;
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
     * Comfortably past every per-credential budget defined in the app, so no
     * test has to restate a limit to prove it is enforced — and comfortably
     * short of the per-address ceilings, which are several times larger so that
     * a fleet sharing an egress address is not throttled for behaving normally.
     */
    private const PAST_ANY_BUDGET = 80;

    /**
     * Past those ceilings too, for the tests that are about them. Reached by
     * looping until the 429 rather than by sending exactly this many, so the
     * number stays an upper bound and not a second copy of the limit.
     */
    private const PAST_ANY_ADDRESS_CEILING = 400;

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

    public function test_uploads_carry_a_budget_per_credential(): void
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
        // keying only on the address.
        $this->withToken($this->issueToken($write)->plainText)
            ->postJson('/upload/acme/widgets')
            ->assertUnprocessable();
    }

    /**
     * The one Composer read endpoint that carries a budget, because it is the
     * one that is not asked per package: an audit posts the whole installed
     * set in a single request, so a ceiling here cannot break the fan-out that
     * makes every other read endpoint unthrottleable.
     */
    public function test_audits_carry_a_budget_per_credential(): void
    {
        $first = $this->issueToken()->plainText;

        for ($audit = 1; $audit <= self::PAST_ANY_BUDGET; $audit++) {
            $this->withToken($first)->postJson('/r/internal/security-advisories', ['packages' => ['acme/widgets']]);
        }

        $this->withToken($first)
            ->postJson('/r/internal/security-advisories', ['packages' => ['acme/widgets']])
            ->assertStatus(429);

        // And a `composer install` from the same address is untouched by it:
        // the fan-out endpoints are where a limit would do the damage.
        $this->withToken($this->issueToken()->plainText)
            ->getJson('/r/internal/p2/acme/widgets.json')
            ->assertOk();
    }

    public function test_an_audit_cannot_name_an_unbounded_number_of_packages(): void
    {
        // One name is one row in an `in (…)` and, on a mirroring repository,
        // one place in an outbound POST. Composer sends what is installed; no
        // lock file names this many.
        $this->withToken($this->issueToken()->plainText)
            ->postJson('/r/internal/security-advisories', [
                'packages' => array_map(fn (int $index): string => "acme/package-{$index}", range(1, 2001)),
            ])
            ->assertUnprocessable();
    }

    /**
     * Keyed by the credential for the same reason uploads are, and applied in
     * front of authentication so an unauthenticated flood is bounded too —
     * which is what puts a ceiling on guessing at tokens here.
     */
    public function test_the_management_api_is_throttled_per_credential(): void
    {
        $first = $this->issueToken([TokenAbility::ApiRead])->plainText;

        for ($request = 1; $request <= self::PAST_ANY_BUDGET; $request++) {
            $this->withToken($first)->getJson('/api/v1/packages');
        }

        $this->withToken($first)->getJson('/api/v1/packages')
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        // A second credential behind the same egress address carries its own
        // budget, which is the whole point of not keying on the address.
        $this->withToken($this->issueToken([TokenAbility::ApiRead])->plainText)
            ->getJson('/api/v1/packages')
            ->assertOk();
    }

    /**
     * The hole a per-credential key cannot close on its own: the guesser picks
     * the key. A different random bearer on every request is a fresh bucket on
     * every request, so a credential budget counts each guess once and never
     * twice, and only the address ceiling ever stops one.
     */
    public function test_a_flood_of_different_bad_credentials_from_one_address_is_throttled(): void
    {
        $attempt = 0;

        // Until the ceiling answers, or until the guessing has gone on long
        // enough to say it never will — which is the failure being tested for.
        do {
            // Never the same credential twice, so nothing but the address is
            // shared between these requests.
            $response = $this->withToken('pp_'.Str::random(40))->getJson('/api/v1/packages');
        } while ($response->getStatusCode() !== 429 && ++$attempt < self::PAST_ANY_ADDRESS_CEILING);

        $response->assertStatus(429)->assertHeader('Retry-After');
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
