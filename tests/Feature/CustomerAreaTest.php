<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Models\BillingCustomer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Models\Token;
use App\Models\User;
use App\Services\Billing\SubscriptionTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The /billing customer area: each customer sees their own commercial
 * records and nobody else's, and token management respects the plan's cap
 * and the subscription's state.
 */
class CustomerAreaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['registry.billing.enabled' => true]);
    }

    /**
     * @return array{0: User, 1: BillingCustomer, 2: Subscription}
     */
    private function customer(?Plan $plan = null): array
    {
        $plan ??= Plan::factory()->create(['token_limit' => 2]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $customer = BillingCustomer::factory()->create([
            'billable_type' => $user->getMorphClass(),
            'billable_id' => $user->getKey(),
        ]);

        $subscription = Subscription::factory()->create([
            'billing_customer_id' => $customer->getKey(),
            'plan_id' => $plan->getKey(),
            'plan_price_id' => PlanPrice::factory()->create(['plan_id' => $plan->getKey()])->getKey(),
        ]);

        return [$user, $customer, $subscription];
    }

    public function test_the_area_shows_own_subscriptions_and_invoices_only(): void
    {
        [$user, $customer, $subscription] = $this->customer(Plan::factory()->create(['name' => 'Mine']));
        Invoice::factory()->create(['billing_customer_id' => $customer->getKey(), 'number' => 'INV-MINE']);

        [, $otherCustomer] = $this->customer(Plan::factory()->create(['name' => 'Theirs']));
        Invoice::factory()->create(['billing_customer_id' => $otherCustomer->getKey(), 'number' => 'INV-THEIRS']);

        $this->actingAs($user)->get('/billing')
            ->assertOk()
            ->assertSee('Mine')
            ->assertSee('INV-MINE')
            ->assertDontSee('Theirs')
            ->assertDontSee('INV-THEIRS');
    }

    public function test_tokens_are_minted_inside_the_cap_and_shown_once(): void
    {
        [$user, , $subscription] = $this->customer();

        $first = $this->actingAs($user)->post("/billing/subscriptions/{$subscription->getKey()}/tokens", ['name' => 'CI']);
        $first->assertRedirect()->assertSessionHas('plainToken');

        $second = $this->actingAs($user)->post("/billing/subscriptions/{$subscription->getKey()}/tokens", ['name' => 'Laptop']);
        $second->assertSessionHas('plainToken');

        // The cap is two; the third is refused with an explanation.
        $third = $this->actingAs($user)->post("/billing/subscriptions/{$subscription->getKey()}/tokens", ['name' => 'One too many']);
        $third->assertSessionMissing('plainToken')->assertSessionHas('status');

        $this->assertSame(2, $subscription->tokens()->count());
    }

    public function test_nobody_can_mint_or_revoke_on_somebody_elses_subscription(): void
    {
        [, , $subscription] = $this->customer();
        $token = app(SubscriptionTokens::class)->issueFor($subscription, 'theirs');

        $stranger = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($stranger)
            ->post("/billing/subscriptions/{$subscription->getKey()}/tokens", ['name' => 'sneaky'])
            ->assertForbidden();

        $this->actingAs($stranger)
            ->delete("/billing/tokens/{$token->token->getKey()}")
            ->assertForbidden();

        $this->assertNotNull(Token::findByPlainText($token->plainText));
    }

    public function test_a_lapsed_subscription_stops_minting(): void
    {
        [$user, , $subscription] = $this->customer();

        $subscription->forceFill(['status' => SubscriptionStatus::Canceled])->save();

        $this->actingAs($user)
            ->post("/billing/subscriptions/{$subscription->getKey()}/tokens", ['name' => 'CI'])
            ->assertForbidden();
    }

    public function test_revoking_ones_own_token_works_and_frees_the_seat(): void
    {
        [$user, , $subscription] = $this->customer();
        $token = app(SubscriptionTokens::class)->issueFor($subscription, 'CI');

        $this->actingAs($user)
            ->delete("/billing/tokens/{$token->token->getKey()}")
            ->assertRedirect();

        $this->assertNull(Token::findByPlainText($token->plainText));
        $this->assertSame(0, $subscription->tokens()->count());
    }

    public function test_a_manual_customer_is_told_there_is_no_portal(): void
    {
        [$user] = $this->customer();

        $this->actingAs($user)->get('/billing/portal')
            ->assertRedirect(route('billing.index'))
            ->assertSessionHas('status');
    }
}
