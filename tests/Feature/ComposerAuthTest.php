<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Token;
use App\Models\User;
use App\Support\NewToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Token authentication on the Composer endpoints: public repositories read
 * openly, private ones require a live token with the read ability.
 */
class ComposerAuthTest extends TestCase
{
    use RefreshDatabase;

    private Repository $private;

    protected function setUp(): void
    {
        parent::setUp();

        $this->private = Repository::factory()->create(['path' => 'internal', 'public' => false]);

        Package::factory()
            ->create(['name' => 'acme/widgets', 'repository_id' => $this->private->id])
            ->versions()->create([
                'version' => 'v1.0.0',
                'reference' => str_repeat('a', 40),
                'is_dev' => false,
                'metadata' => ['name' => 'acme/widgets', 'version' => 'v1.0.0'],
            ]);
    }

    private function issueToken(array $abilities = [TokenAbility::RepositoryRead]): NewToken
    {
        // A personal token reaches what its owner can see; these tests are
        // about authentication, so the owner is someone who sees everything.
        // Row-level scoping has its own coverage in UserAccessScopingTest.
        return Token::issue(User::factory()->superAdmin()->create(), 'test token', $abilities);
    }

    public function test_a_private_repository_rejects_unauthenticated_reads(): void
    {
        foreach ([
            '/r/internal/packages.json',
            '/r/internal/search.json?q=acme',
            '/r/internal/list.json',
            '/r/internal/p2/acme/widgets.json',
            '/r/internal/dist/acme/widgets/'.str_repeat('a', 40).'.zip',
        ] as $url) {
            $this->getJson($url)
                ->assertUnauthorized()
                ->assertHeader('WWW-Authenticate', 'Basic realm="Composer repository"');
        }
    }

    public function test_the_401_tells_the_user_how_to_authenticate(): void
    {
        $message = $this->getJson('/r/internal/packages.json')->json('message');

        $this->assertStringContainsString('composer config http-basic.', $message);
    }

    public function test_a_public_repository_reads_without_a_token(): void
    {
        $this->private->update(['public' => true]);

        $this->getJson('/r/internal/list.json')->assertOk();
    }

    public function test_a_valid_token_authenticates_as_the_basic_password_with_any_username(): void
    {
        $new = $this->issueToken();

        $this->withBasicAuth('anything-at-all', $new->plainText)
            ->getJson('/r/internal/list.json')
            ->assertOk()
            ->assertExactJson(['packageNames' => ['acme/widgets']]);
    }

    public function test_a_valid_token_authenticates_as_a_bearer_token(): void
    {
        $new = $this->issueToken();

        $this->withToken($new->plainText)
            ->getJson('/r/internal/p2/acme/widgets.json')
            ->assertOk();
    }

    public function test_an_unknown_token_is_rejected_even_on_a_public_repository(): void
    {
        // A CI system with a bad credential should hear about it now, not
        // keep working by accident until the repository goes private.
        $this->private->update(['public' => true]);

        $this->withBasicAuth('token', 'pp_not-a-real-token')
            ->getJson('/r/internal/list.json')
            ->assertUnauthorized();
    }

    public function test_a_revoked_token_stops_authenticating(): void
    {
        $new = $this->issueToken();

        $new->token->delete();

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/internal/list.json')
            ->assertUnauthorized();
    }

    public function test_an_expired_token_stops_authenticating(): void
    {
        $new = Token::issue(User::factory()->superAdmin()->create(), 'expired', [TokenAbility::RepositoryRead], now()->subDay());

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/internal/list.json')
            ->assertUnauthorized();
    }

    public function test_reading_requires_the_read_ability(): void
    {
        $new = $this->issueToken([TokenAbility::RepositoryWrite]);

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/internal/list.json')
            ->assertForbidden();
    }

    public function test_using_a_token_records_when(): void
    {
        $new = $this->issueToken();

        $this->assertNull($new->token->last_used_at);

        $this->withBasicAuth('token', $new->plainText)->getJson('/r/internal/list.json')->assertOk();

        $this->assertNotNull($new->token->fresh()->last_used_at);
    }

    public function test_the_default_repository_stays_open_while_public(): void
    {
        // The default repository is created public, so a registry upgrading
        // to token auth serves its existing consumers exactly as before.
        Package::factory()->create(['name' => 'acme/open'])
            ->versions()->create([
                'version' => 'v1.0.0',
                'reference' => str_repeat('b', 40),
                'is_dev' => false,
                'metadata' => ['name' => 'acme/open', 'version' => 'v1.0.0'],
            ]);

        $this->getJson('/packages.json')->assertOk();
        $this->getJson('/list.json')->assertOk();

        Repository::default()->update(['public' => false]);

        $this->getJson('/list.json')->assertUnauthorized();
    }
}
