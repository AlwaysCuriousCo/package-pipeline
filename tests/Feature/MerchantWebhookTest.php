<?php

namespace Tests\Feature;

use App\Enums\BillingModel;
use App\Enums\LapseBehaviour;
use App\Enums\SubscriptionStatus;
use App\Jobs\ProcessMerchantEvent;
use App\Models\BillingCustomer;
use App\Models\Invoice;
use App\Models\MerchantEvent;
use App\Models\MerchantReference;
use App\Models\Package;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Repository;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\EntitlementProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The merchant webhook inbox: verify against the raw body, record before
 * acknowledging, act from the queue, and treat every redelivery as a no-op.
 *
 * Stripe's signature scheme is an HMAC this test can compute for itself, so
 * the verification path exercised here is the real driver's — nothing is
 * faked between the HTTP request and the row in merchant_events.
 */
class MerchantWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'registry.billing.enabled' => true,
            'services.stripe.secret' => 'sk_test_fake',
            'services.stripe.webhook_secret' => self::SECRET,
        ]);
    }

    /**
     * A delivery signed the way Stripe signs: v1 = HMAC-SHA256("{t}.{body}").
     *
     * @param  array<string, mixed>  $event
     * @return TestResponse<Response>
     */
    private function deliver(array $event, ?string $secret = null): TestResponse
    {
        $body = (string) json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$body}", $secret ?? self::SECRET);

        return $this->call(
            'POST',
            '/billing/stripe/webhook',
            server: [
                'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $body,
        );
    }

    /**
     * @param  array<string, mixed>  $object
     * @return array<string, mixed>
     */
    private function event(string $id, string $type, array $object): array
    {
        return [
            'id' => $id,
            'object' => 'event',
            'type' => $type,
            'created' => time(),
            'data' => ['object' => $object],
        ];
    }

    public function test_an_unsigned_delivery_is_refused_and_records_nothing(): void
    {
        $this->post('/billing/stripe/webhook', [], ['Content-Type' => 'application/json'])
            ->assertStatus(400);

        $this->assertDatabaseCount('merchant_events', 0);
    }

    public function test_a_delivery_signed_with_the_wrong_secret_is_refused(): void
    {
        $this->deliver(
            $this->event('evt_1', 'invoice.paid', ['id' => 'in_1']),
            secret: 'whsec_not_ours',
        )->assertStatus(400);

        $this->assertDatabaseCount('merchant_events', 0);
    }

    public function test_a_verified_delivery_is_recorded_and_queued(): void
    {
        Queue::fake();

        $this->deliver($this->event('evt_1', 'invoice.paid', ['id' => 'in_1', 'customer' => 'cus_x']))
            ->assertNoContent();

        $this->assertDatabaseHas('merchant_events', [
            'external_id' => 'evt_1',
            'type' => 'invoice.paid',
        ]);

        Queue::assertPushed(ProcessMerchantEvent::class, 1);
    }

    public function test_a_redelivery_is_acknowledged_without_being_processed_twice(): void
    {
        Queue::fake();

        $event = $this->event('evt_dup', 'invoice.paid', ['id' => 'in_1', 'customer' => 'cus_x']);

        $this->deliver($event)->assertNoContent();
        $this->deliver($event)->assertNoContent();

        $this->assertSame(1, MerchantEvent::query()->where('external_id', 'evt_dup')->count());
        Queue::assertPushed(ProcessMerchantEvent::class, 1);
    }

    public function test_an_event_nobody_acts_on_is_verified_and_not_recorded(): void
    {
        Queue::fake();

        $this->deliver($this->event('evt_noise', 'customer.updated', ['id' => 'cus_x']))
            ->assertNoContent();

        $this->assertDatabaseCount('merchant_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_webhooks_disabled_while_billing_is_off(): void
    {
        config(['registry.billing.enabled' => false]);

        $this->deliver($this->event('evt_1', 'invoice.paid', ['id' => 'in_1']))
            ->assertNotFound();
    }

    public function test_a_one_time_checkout_completes_into_a_frozen_style_subscription(): void
    {
        // Queue runs inline (sync) in tests, so the completion webhook does
        // the whole job: subscription row, projection, session reference.
        $repository = Repository::factory()->create(['path' => 'paid', 'public' => false]);
        $package = Package::factory()->create(['name' => 'acme/widgets', 'repository_id' => $repository->id]);

        $plan = Plan::factory()->perpetual()->create();
        $plan->entitlements()->create([
            'grantable_type' => Package::class,
            'grantable_id' => $package->getKey(),
        ]);
        $price = PlanPrice::factory()->oneTime()->create(['plan_id' => $plan->getKey()]);

        $user = User::factory()->create();
        $customer = BillingCustomer::factory()->stripe()->create([
            'billable_type' => $user->getMorphClass(),
            'billable_id' => $user->getKey(),
        ]);

        $this->deliver($this->event('evt_checkout', 'checkout.session.completed', [
            'id' => 'cs_test_1',
            'customer' => $customer->merchant_customer_id,
            'subscription' => null,
            'metadata' => [
                'billing_customer_id' => (string) $customer->getKey(),
                'plan_id' => (string) $plan->getKey(),
                'plan_price_id' => (string) $price->getKey(),
            ],
        ]))->assertNoContent();

        $subscription = Subscription::query()->sole();

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertNotNull($subscription->current_period_end);

        // The purchase granted the package.
        $this->assertTrue(
            Package::query()->visibleToUser($user->fresh())->whereKey($package->getKey())->exists(),
        );

        // The session id is remembered, which is all the welcome page holds.
        $this->assertTrue(
            MerchantReference::resolve($subscription->merchant, 'cs_test_1')?->is($subscription) ?? false,
        );

        // The welcome page mints and shows the activation token, exactly once.
        $first = $this->actingAs($user)->get('/billing/welcome?session=cs_test_1');
        $first->assertOk()->assertSee('pp_');

        $this->actingAs($user)->get('/billing/welcome?session=cs_test_1')
            ->assertOk()->assertDontSee('pp_');
    }

    public function test_a_full_refund_withdraws_access_immediately(): void
    {
        [$user, $package, $subscription, $customer] = $this->activeSubscription();

        $invoice = Invoice::factory()->create([
            'billing_customer_id' => $customer->getKey(),
            'subscription_id' => $subscription->getKey(),
            'subtotal' => 1000,
            'total' => 1000,
        ]);
        MerchantReference::remember($invoice, $invoice->merchant, 'in_refund');

        $this->deliver($this->event('evt_refund', 'charge.refunded', [
            'id' => 'ch_1',
            'invoice' => 'in_refund',
            'customer' => $customer->merchant_customer_id,
            'amount_refunded' => 1000,
        ]))->assertNoContent();

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        $this->assertFalse(
            Package::query()->visibleToUser($user->fresh())->whereKey($package->getKey())->exists(),
        );
    }

    public function test_a_dispute_suspends_every_subscription_of_the_customer(): void
    {
        [$user, $package, $subscription, $customer] = $this->activeSubscription();

        $this->deliver($this->event('evt_dispute', 'charge.dispute.created', [
            'id' => 'dp_1',
            'charge' => 'ch_1',
            'customer' => $customer->merchant_customer_id,
        ]))->assertNoContent();

        $this->assertNotNull($subscription->fresh()->suspended_at);
        $this->assertFalse(
            Package::query()->visibleToUser($user->fresh())->whereKey($package->getKey())->exists(),
        );
    }

    /**
     * @return array{0: User, 1: Package, 2: Subscription, 3: BillingCustomer}
     */
    private function activeSubscription(): array
    {
        $repository = Repository::factory()->create(['path' => 'paid', 'public' => false]);
        $package = Package::factory()->create(['name' => 'acme/widgets', 'repository_id' => $repository->id]);

        $plan = Plan::factory()->create(['lapse_behaviour' => LapseBehaviour::WithdrawEntitlement, 'billing_model' => BillingModel::Recurring]);
        $plan->entitlements()->create([
            'grantable_type' => Package::class,
            'grantable_id' => $package->getKey(),
        ]);

        $user = User::factory()->create();
        $customer = BillingCustomer::factory()->stripe()->create([
            'billable_type' => $user->getMorphClass(),
            'billable_id' => $user->getKey(),
        ]);

        $subscription = Subscription::factory()->create([
            'billing_customer_id' => $customer->getKey(),
            'plan_id' => $plan->getKey(),
            'plan_price_id' => PlanPrice::factory()->create(['plan_id' => $plan->getKey()])->getKey(),
        ]);

        (new EntitlementProjector)->project($subscription);

        $this->assertTrue(
            Package::query()->visibleToUser($user->fresh())->whereKey($package->getKey())->exists(),
        );

        return [$user, $package, $subscription, $customer];
    }
}
