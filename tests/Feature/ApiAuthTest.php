<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who may reach /api/v1 at all, and with which ability.
 *
 * The load-bearing tests here are the ones proving the two credential families
 * do not bleed into each other: the token pasted into every developer's
 * auth.json so `composer install` works must not, by that act, be able to read
 * the registry's administration or delete a package.
 */
class ApiAuthTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        // Public, so nothing below can pass by being world-readable over the
        // Composer protocol — the API has no anonymous path either way.
        $this->package = Package::factory()->create([
            'name' => 'acme/widgets',
            'repository_id' => Repository::factory()->public()->create()->id,
        ]);
    }

    /**
     * @param  list<TokenAbility>  $abilities
     */
    private function tokenFor(array $abilities): string
    {
        return Token::issue(User::factory()->superAdmin()->create(), 'ci', $abilities)->plainText;
    }

    public function test_every_endpoint_requires_a_token(): void
    {
        $this->getJson('/api/v1/packages')->assertUnauthorized();
        $this->getJson("/api/v1/packages/{$this->package->id}")->assertUnauthorized();
        $this->getJson('/api/v1/repositories')->assertUnauthorized();
        $this->postJson('/api/v1/packages', [])->assertUnauthorized();
        $this->postJson("/api/v1/packages/{$this->package->id}/sync")->assertUnauthorized();
        $this->deleteJson("/api/v1/packages/{$this->package->id}")->assertUnauthorized();
    }

    public function test_a_revoked_token_stops_working(): void
    {
        $token = Token::issue(User::factory()->superAdmin()->create(), 'ci', [TokenAbility::ApiRead]);

        $this->withToken($token->plainText)->getJson('/api/v1/packages')->assertOk();

        $token->token->delete();

        $this->withToken($token->plainText)->getJson('/api/v1/packages')->assertUnauthorized();
    }

    public function test_an_expired_token_stops_working(): void
    {
        $plain = Token::issue(
            User::factory()->superAdmin()->create(),
            'ci',
            [TokenAbility::ApiRead],
            now()->subMinute(),
        )->plainText;

        $this->withToken($plain)->getJson('/api/v1/packages')->assertUnauthorized();
    }

    /**
     * The whole reason the api:* abilities exist rather than reusing
     * repository:*: a credential that installs packages reaches none of this.
     */
    public function test_a_composer_token_reaches_nothing_on_the_api(): void
    {
        $plain = $this->tokenFor([TokenAbility::RepositoryRead, TokenAbility::RepositoryWrite]);

        // It is a perfectly good Composer credential...
        $this->withToken($plain)->getJson('/packages.json')->assertOk();

        // ...and nothing at all here.
        $this->withToken($plain)->getJson('/api/v1/packages')->assertForbidden();
        $this->withToken($plain)->postJson('/api/v1/packages', [])->assertForbidden();
        $this->withToken($plain)->deleteJson("/api/v1/packages/{$this->package->id}")->assertForbidden();
    }

    public function test_reading_does_not_grant_writing(): void
    {
        $plain = $this->tokenFor([TokenAbility::ApiRead]);

        $this->withToken($plain)->getJson('/api/v1/packages')->assertOk();

        $this->withToken($plain)
            ->postJson('/api/v1/packages', ['url' => 'https://github.com/acme/gadgets'])
            ->assertForbidden();

        $this->withToken($plain)
            ->postJson("/api/v1/packages/{$this->package->id}/sync")
            ->assertForbidden();
    }

    /**
     * Deleting is not something a release pipeline's credential does, so
     * api:write does not carry it.
     */
    public function test_writing_does_not_grant_deleting(): void
    {
        $plain = $this->tokenFor([TokenAbility::ApiRead, TokenAbility::ApiWrite]);

        $response = $this->withToken($plain)->deleteJson("/api/v1/packages/{$this->package->id}");

        $response->assertForbidden();
        $this->assertStringContainsString('api:delete', (string) $response->json('message'));
        $this->assertDatabaseHas('packages', ['id' => $this->package->id]);
    }

    /**
     * Deliberately not the Basic challenge AuthenticateComposer sends: that
     * header exists to make an interactive `composer install` prompt, and a
     * browser pointed at this URL would only get a dialog over a JSON body.
     */
    public function test_the_401_carries_no_basic_challenge(): void
    {
        $this->getJson('/api/v1/packages')
            ->assertUnauthorized()
            ->assertHeaderMissing('WWW-Authenticate');
    }

    /**
     * The same auth.json entry a Composer client already holds works from
     * curl, since that is where a CI script's credential already lives.
     */
    public function test_a_token_may_also_arrive_as_the_basic_password(): void
    {
        $this->withBasicAuth('token', $this->tokenFor([TokenAbility::ApiRead]))
            ->getJson('/api/v1/packages')
            ->assertOk();
    }

    /**
     * Both are encrypted casts on the model, so anything serialising it
     * wholesale would have decrypted them on the way out.
     */
    public function test_no_response_carries_a_packages_secrets(): void
    {
        $this->package->forceFill([
            'token' => 'ghp_the-registrys-github-credential',
            'webhook_secret' => 'the-delivery-signing-secret',
        ])->save();

        $plain = $this->tokenFor([TokenAbility::ApiRead]);

        foreach (['/api/v1/packages', "/api/v1/packages/{$this->package->id}"] as $url) {
            $body = $this->withToken($plain)->getJson($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('ghp_the-registrys-github-credential', (string) $body);
            $this->assertStringNotContainsString('the-delivery-signing-secret', (string) $body);
            $this->assertStringNotContainsString('webhook_secret', (string) $body);
        }
    }
}
