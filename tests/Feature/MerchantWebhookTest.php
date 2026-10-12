<?php

namespace Tests\Feature;

use App\Enums\BillingModel;
use App\Enums\LapseBehaviour;
use App\Enums\MerchantProvider;
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
use Tests\Support\FakeStripeHttp;
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

    protected function tearDown(): void
    {
        FakeStripeHttp::uninstall();

        parent::tearDown();
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

    public function test_the_welcome_page_finds_a_subscription_checkout_through_the_recorded_event(): void
    {
        [$user, , $subscription] = $this->activeSubscription();

        // What checkoutCompleted leaves behind for a subscription checkout:
        // the one reference slot holds the subscription id every webhook
        // resolves by, and the session id lives only in the recorded event.
        $subscription->forceFill(['merchant' => MerchantProvider::Stripe])->save();
        MerchantReference::remember($subscription, MerchantProvider::Stripe, 'sub_welcome');

        MerchantEvent::create([
            'merchant' => MerchantProvider::Stripe,
            'external_id' => 'evt_welcome',
            'type' => 'checkout.session.completed',
            'payload' => ['id' => 'cs_welcome', 'subscription' => 'sub_welcome'],
            'received_at' => now(),
        ]);

        $this->actingAs($user)->get('/billing/welcome?session=cs_welcome')
            ->assertOk()
            ->assertSee('pp_');
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

    public function test_a_refund_that_does_not_name_its_invoice_finds_it_through_the_payment(): void
    {
        // API versions from 2025-03-31.basil dropped `invoice` from the
        // charge; the invoice is reached through the charge's payment intent.
        [$user, $package, $subscription, $customer] = $this->activeSubscription();

        $invoice = Invoice::factory()->create([
            'billing_customer_id' => $customer->getKey(),
            'subscription_id' => $subscription->getKey(),
            'subtotal' => 1000,
            'total' => 1000,
        ]);
        MerchantReference::remember($invoice, $invoice->merchant, 'in_basil');

        FakeStripeHttp::install()
            ->answerList('/v1/invoice_payments', [['id' => 'inpay_1', 'object' => 'invoice_payment', 'invoice' => 'in_basil']])
            ->answerList('/v1/checkout/sessions', []);

        $this->deliver($this->event('evt_refund_basil', 'charge.refunded', [
            'id' => 'ch_1',
            'object' => 'charge',
            'payment_intent' => 'pi_1',
            'customer' => $customer->merchant_customer_id,
            'amount' => 1000,
            'amount_refunded' => 1000,
            'refunded' => true,
        ]))->assertNoContent();

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        $this->assertSame(1000, $invoice->fresh()->amount_refunded);
        $this->assertFalse(
            Package::query()->visibleToUser($user->fresh())->whereKey($package->getKey())->exists(),
        );
    }

    public function test_a_full_refund_of_a_one_time_purchase_withdraws_access(): void
    {
        // A one-time purchase belongs to no subscription at the merchant, so
        // no invoice leads back to it; the checkout session that took the
        // payment does. A refund also outranks the plan's freeze: nothing
        // stays installable from a purchase that was paid back.
        [$user, $package, $subscription] = $this->oneTimePurchase('cs_refund');

        FakeStripeHttp::install()
            ->answerList('/v1/invoice_payments', [])
            ->answerList('/v1/checkout/sessions', [['id' => 'cs_refund', 'object' => 'checkout.session']]);

        $this->deliver($this->event('evt_refund_once', 'charge.refunded', [
            'id' => 'ch_2',
            'object' => 'charge',
            'payment_intent' => 'pi_2',
            'customer' => $subscription->customer->merchant_customer_id,
            'amount' => 4900,
            'amount_refunded' => 4900,
            'refunded' => true,
        ]))->assertNoContent();

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        $this->assertFalse(
            Package::query()->visibleToUser($user->fresh())->whereKey($package->getKey())->exists(),
        );
    }

    public function test_a_refund_of_a_one_time_purchase_that_names_its_invoice_withdraws_access(): void
    {
        // Before basil the charge names its invoice, but a one-time purchase's
        // invoice belongs to no subscription (the mirror stores it with none),
        // so the purchase is still found through its checkout session.
        [$user, $package, $subscription] = $this->oneTimePurchase('cs_legacy');

        $invoice = Invoice::factory()->create([
            'billing_customer_id' => $subscription->billing_customer_id,
            'subscription_id' => null,
            'subtotal' => 4900,
            'total' => 4900,
        ]);
        MerchantReference::remember($invoice, $invoice->merchant, 'in_once');

        FakeStripeHttp::install()
            ->answerList('/v1/checkout/sessions', [['id' => 'cs_legacy', 'object' => 'checkout.session']]);

        $this->deliver($this->event('evt_refund_legacy', 'charge.refunded', [
            'id' => 'ch_4',
            'object' => 'charge',
            'invoice' => 'in_once',
            'payment_intent' => 'pi_4',
            'customer' => $subscription->customer->merchant_customer_id,
            'amount' => 4900,
            'amount_refunded' => 4900,
            'refunded' => true,
        ]))->assertNoContent();

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        $this->assertFalse(
            Package::query()->visibleToUser($user->fresh())->whereKey($package->getKey())->exists(),
        );
    }

    public function test_a_full_refund_outranks_a_freeze_at_version_plan(): void
    {
        // The refund is matched (its invoice names the purchase); what is under
        // test is that cancelling does not hand the plan's freeze a keepsake.
        [$user, $package, $subscription] = $this->oneTimePurchase('cs_freeze');

        $invoice = Invoice::factory()->create([
            'billing_customer_id' => $subscription->billing_customer_id,
            'subscription_id' => $subscription->getKey(),
            'subtotal' => 4900,
            'total' => 4900,
        ]);
        MerchantReference::remember($invoice, $invoice->merchant, 'in_freeze');

        $this->deliver($this->event('evt_refund_freeze', 'charge.refunded', [
            'id' => 'ch_5',
            'invoice' => 'in_freeze',
            'customer' => $subscription->customer->merchant_customer_id,
            'amount_refunded' => 4900,
        ]))->assertNoContent();

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        $this->assertFalse(
            Package::query()->visibleToUser($user->fresh())->whereKey($package->getKey())->exists(),
        );
    }

    public function test_a_partial_refund_of_a_one_time_purchase_keeps_access(): void
    {
        [$user, $package, $subscription] = $this->oneTimePurchase('cs_partial');

        FakeStripeHttp::install()
            ->answerList('/v1/invoice_payments', [])
            ->answerList('/v1/checkout/sessions', [['id' => 'cs_partial', 'object' => 'checkout.session']]);

        $this->deliver($this->event('evt_refund_part', 'charge.refunded', [
            'id' => 'ch_3',
            'object' => 'charge',
            'payment_intent' => 'pi_3',
            'customer' => $subscription->customer->merchant_customer_id,
            'amount' => 4900,
            'amount_refunded' => 1000,
            'refunded' => false,
        ]))->assertNoContent();

        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
        $this->assertTrue(
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
     * A completed one-time purchase on a freeze-at-version plan, remembered
     * by the checkout session that bought it, as checkoutCompleted leaves it.
     *
     * @return array{0: User, 1: Package, 2: Subscription}
     */
    private function oneTimePurchase(string $sessionId): array
    {
        $repository = Repository::factory()->create(['path' => 'paid', 'public' => false]);
        $package = Package::factory()->create(['name' => 'acme/widgets', 'repository_id' => $repository->id]);

        $plan = Plan::factory()->perpetual()->create();
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
            'plan_price_id' => PlanPrice::factory()->oneTime()->create(['plan_id' => $plan->getKey()])->getKey(),
            'merchant' => MerchantProvider::Stripe,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonths(12),
        ]);
        MerchantReference::remember($subscription, MerchantProvider::Stripe, $sessionId);

        (new EntitlementProjector)->project($subscription);

        $this->assertTrue(
            Package::query()->visibleToUser($user->fresh())->whereKey($package->getKey())->exists(),
        );

        return [$user, $package, $subscription];
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
