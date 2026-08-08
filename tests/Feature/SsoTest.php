<?php

namespace Tests\Feature;

use App\Auth\SsoProviderFactory;
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
     */
    private function returningIdentity(string $id, ?string $email, ?string $name = null): void
    {
        $identity = (new SocialiteUser)->map(['id' => $id, 'email' => $email, 'name' => $name]);

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
