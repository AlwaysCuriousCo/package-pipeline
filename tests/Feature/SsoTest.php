<?php

namespace Tests\Feature;

use App\Auth\SsoProviderFactory;
use App\Enums\AuthProvider;
use App\Models\AuthenticationSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Runtime-configured SSO: the login page advertises active providers, and
 * the callback resolves an external identity to a panel account.
 */
class SsoTest extends TestCase
{
    use RefreshDatabase;

    private AuthenticationSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('developer', 'web');

        $this->source = AuthenticationSource::factory()->create([
            'name' => 'Acme SSO',
            'default_role' => 'developer',
        ]);
    }

    /**
     * Swap the provider factory for one that returns this identity, skipping
     * the real OAuth round trip.
     *
     * @param  array<string, mixed>  $claims  Raw provider claims, as an OIDC userinfo response carries them.
     */
    private function returningIdentity(string $id, ?string $email, ?string $name = null, array $claims = []): void
    {
        $identity = (new SocialiteUser)
            ->setRaw($claims)
            ->map(['id' => $id, 'email' => $email, 'name' => $name]);

        $this->mock(SsoProviderFactory::class)
            ->shouldReceive('provider')
            ->andReturn(new class($identity) implements Provider
            {
                public function __construct(private readonly SocialiteUser $identity) {}

                public function redirect()
                {
                    return redirect('https://idp.example/authorize');
                }

                public function user()
                {
                    return $this->identity;
                }
            });
    }

    public function test_the_login_page_lists_active_providers_only(): void
    {
        AuthenticationSource::factory()->inactive()->create(['name' => 'Dormant IdP']);

        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Continue with Acme SSO')
            ->assertDontSee('Dormant IdP');
    }

    public function test_the_redirect_leg_sends_the_browser_to_the_provider(): void
    {
        $response = $this->get(route('sso.redirect', $this->source));

        $response->assertRedirect();

        $this->assertStringContainsString('github.com/login/oauth/authorize', $response->headers->get('Location'));
        $this->assertStringContainsString($this->source->client_id, $response->headers->get('Location'));
    }

    public function test_an_inactive_provider_is_unreachable(): void
    {
        $dormant = AuthenticationSource::factory()->inactive()->create();

        $this->get(route('sso.redirect', $dormant))->assertNotFound();
        $this->get(route('sso.callback', $dormant))->assertNotFound();
    }

    public function test_a_bound_identity_signs_its_user_in(): void
    {
        $user = User::factory()->create();
        $user->assignRole('developer');
        $user->forceFill([
            'authentication_source_id' => $this->source->id,
            'external_id' => 'ext-42',
        ])->save();

        $this->returningIdentity('ext-42', $user->email);

        $this->get(route('sso.callback', $this->source))->assertRedirect('/admin');

        $this->assertAuthenticatedAs($user);
    }

    public function test_an_existing_account_is_matched_by_email_and_bound(): void
    {
        $user = User::factory()->create(['email' => 'dev@example.com']);
        $user->assignRole('developer');

        $this->returningIdentity('ext-7', 'dev@example.com');

        $this->get(route('sso.callback', $this->source))->assertRedirect('/admin');

        $user->refresh();

        $this->assertAuthenticatedAs($user);
        $this->assertSame($this->source->id, $user->authentication_source_id);
        $this->assertSame('ext-7', $user->external_id);
    }

    public function test_a_verified_oidc_identity_adopts_an_existing_account(): void
    {
        $this->source->update(['provider' => AuthProvider::Oidc]);

        $user = User::factory()->create(['email' => 'dev@example.com']);
        $user->assignRole('developer');

        $this->returningIdentity('ext-7', 'dev@example.com', claims: ['email_verified' => true]);

        $this->get(route('sso.callback', $this->source))->assertRedirect('/admin');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('ext-7', $user->refresh()->external_id);
    }

    public function test_the_email_verified_claim_is_honoured_as_a_string(): void
    {
        // Not every issuer sends the claim as a JSON boolean.
        $this->source->update(['provider' => AuthProvider::Oidc]);

        $user = User::factory()->create(['email' => 'dev@example.com']);
        $user->assignRole('developer');

        $this->returningIdentity('ext-7', 'dev@example.com', claims: ['email_verified' => 'true']);

        $this->get(route('sso.callback', $this->source))->assertRedirect('/admin');

        $this->assertAuthenticatedAs($user);
        $this->assertSame('ext-7', $user->refresh()->external_id);
    }

    public function test_an_unverified_oidc_identity_cannot_adopt_an_existing_account(): void
    {
        $this->source->update(['provider' => AuthProvider::Oidc]);

        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $admin->assignRole('developer');

        // An issuer an admin pointed the source at can assert any address it
        // likes; without the claim it has vouched for nothing.
        foreach ([[], ['email_verified' => false], ['email_verified' => 'false']] as $claims) {
            $this->returningIdentity('ext-7', 'admin@example.com', claims: $claims);

            $this->get(route('sso.callback', $this->source))
                ->assertRedirect(route('filament.admin.auth.login'))
                ->assertSessionHas('sso_error');

            $this->assertGuest();
            $this->assertNull($admin->refresh()->external_id);
        }
    }

    public function test_an_address_outside_the_allowlist_cannot_adopt_an_existing_account(): void
    {
        $this->source->update(['allowed_domains' => ['example.com']]);

        $admin = User::factory()->create(['email' => 'admin@elsewhere.io']);
        $admin->assignRole('developer');

        $this->returningIdentity('ext-7', 'admin@elsewhere.io');

        $this->get(route('sso.callback', $this->source))
            ->assertRedirect(route('filament.admin.auth.login'))
            ->assertSessionHas('sso_error');

        $this->assertGuest();
        $this->assertNull($admin->refresh()->external_id);
    }

    public function test_an_account_bound_to_another_source_is_refused_rather_than_rebound(): void
    {
        $other = AuthenticationSource::factory()->create();

        $user = User::factory()->create(['email' => 'dev@example.com']);
        $user->assignRole('developer');
        $user->forceFill([
            'authentication_source_id' => $other->id,
            'external_id' => 'other-1',
        ])->save();

        $this->returningIdentity('ext-7', 'dev@example.com');

        $this->get(route('sso.callback', $this->source))
            ->assertRedirect(route('filament.admin.auth.login'))
            ->assertSessionHas('sso_error');

        $this->assertGuest();

        $user->refresh();

        $this->assertSame($other->id, $user->authentication_source_id);
        $this->assertSame('other-1', $user->external_id);
    }

    public function test_an_unknown_identity_registers_just_in_time_with_the_default_role(): void
    {
        $this->returningIdentity('ext-9', 'new@example.com', 'New Dev');

        $this->get(route('sso.callback', $this->source))->assertRedirect('/admin');

        $user = User::query()->where('email', 'new@example.com')->sole();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('New Dev', $user->name);
        $this->assertTrue($user->hasRole('developer'));
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('ext-9', $user->external_id);
    }

    public function test_registration_respects_the_domain_allowlist(): void
    {
        $this->source->update(['allowed_domains' => ['example.com']]);

        $this->returningIdentity('ext-9', 'stranger@elsewhere.io');

        $this->get(route('sso.callback', $this->source))
            ->assertRedirect(route('filament.admin.auth.login'))
            ->assertSessionHas('sso_error');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'stranger@elsewhere.io']);
    }

    public function test_registration_can_be_switched_off(): void
    {
        $this->source->update(['allow_registration' => false]);

        $this->returningIdentity('ext-9', 'new@example.com');

        $this->get(route('sso.callback', $this->source))
            ->assertRedirect(route('filament.admin.auth.login'))
            ->assertSessionHas('sso_error');

        $this->assertGuest();
    }

    public function test_changing_the_discovery_url_drops_the_cached_discovery_document(): void
    {
        cache()->put("oidc-discovery:{$this->source->id}", ['authorization_endpoint' => 'https://old-idp.example/authorize'], 3600);

        $this->source->update(['name' => 'Renamed SSO']);

        $this->assertNotNull(cache()->get("oidc-discovery:{$this->source->id}"), 'An unrelated edit should keep the cache.');

        $this->source->update(['discovery_url' => 'https://new-idp.example/.well-known/openid-configuration']);

        $this->assertNull(cache()->get("oidc-discovery:{$this->source->id}"));
    }

    public function test_deleting_a_source_drops_the_cached_discovery_document(): void
    {
        cache()->put("oidc-discovery:{$this->source->id}", ['authorization_endpoint' => 'https://old-idp.example/authorize'], 3600);

        $this->source->delete();

        $this->assertNull(cache()->get("oidc-discovery:{$this->source->id}"));
    }

    public function test_a_matched_account_without_a_role_is_told_so_rather_than_403ed(): void
    {
        $user = User::factory()->create(['email' => 'dev@example.com']);

        $this->returningIdentity('ext-7', 'dev@example.com');

        $this->get(route('sso.callback', $this->source))
            ->assertRedirect(route('filament.admin.auth.login'))
            ->assertSessionHas('sso_error');

        $this->assertGuest();
    }
}
