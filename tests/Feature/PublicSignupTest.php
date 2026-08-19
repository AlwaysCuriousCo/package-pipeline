<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Notifications\Billing\VerifyBillingEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Self-serve signup: exists only while switched on, creates accounts that
 * cannot reach /admin, and requires a verified address before checkout.
 */
class PublicSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'registry.billing.enabled' => true,
            'registry.billing.public_signup' => true,
        ]);
    }

    public function test_signup_is_a_404_until_switched_on(): void
    {
        config(['registry.billing.public_signup' => false]);

        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
    }

    public function test_registration_creates_a_roleless_account_that_cannot_reach_the_panel(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Buyer',
            'email' => 'buyer@example.com',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertRedirect(route('billing.index'));

        $user = User::query()->where('email', 'buyer@example.com')->sole();

        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->canAccessPanel(filament()->getDefaultPanel()));
        Notification::assertSentTo($user, VerifyBillingEmail::class);

        // Signed in, but the panel's front door stays shut.
        $this->get('/admin')->assertForbidden();
    }

    public function test_the_honeypot_swallows_bots_without_creating_anything(): void
    {
        $this->post('/register', [
            'name' => 'Bot',
            'email' => 'bot@example.com',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
            'website' => 'https://spam.example',
        ])->assertRedirect(route('billing.index'));

        $this->assertDatabaseMissing('users', ['email' => 'bot@example.com']);
    }

    public function test_the_signed_link_verifies_the_address(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Buyer',
            'email' => 'buyer@example.com',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ]);

        $user = User::query()->where('email', 'buyer@example.com')->sole();
        $this->assertNull($user->email_verified_at);

        $url = URL::temporarySignedRoute('billing.verify', now()->addDay(), ['user' => $user->getKey()]);

        $this->get($url)->assertRedirect(route('billing.index'));
        $this->assertNotNull($user->fresh()->email_verified_at);

        // A tampered link verifies nothing.
        $other = User::factory()->create(['email_verified_at' => null]);
        $this->get(str_replace("/billing/verify/{$user->getKey()}", "/billing/verify/{$other->getKey()}", $url))->assertForbidden();
        $this->assertNull($other->fresh()->email_verified_at);
    }

    public function test_the_customer_area_requires_signing_in(): void
    {
        $this->get('/billing')->assertRedirect();
    }

    public function test_the_pricing_page_lists_only_listed_plans(): void
    {
        Plan::factory()->listed()->create(['name' => 'Advertised']);
        $unlisted = Plan::factory()->create(['name' => 'Negotiated']);

        $this->get('/pricing')
            ->assertOk()
            ->assertSee('Advertised')
            ->assertDontSee('Negotiated');

        // Unlisted is still reachable by direct link, 404 only when inactive.
        $this->get("/pricing/{$unlisted->slug}")->assertOk();

        $unlisted->forceFill(['active' => false])->save();
        $this->get("/pricing/{$unlisted->slug}")->assertNotFound();
    }

    public function test_the_whole_public_surface_is_a_404_while_billing_is_off(): void
    {
        config(['registry.billing.enabled' => false]);

        $plan = Plan::factory()->listed()->create();

        $this->get('/pricing')->assertNotFound();
        $this->get("/pricing/{$plan->slug}")->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/billing')->assertNotFound();
    }
}
