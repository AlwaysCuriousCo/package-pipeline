<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Merchants\Values\RemoteSubscription;
use App\Models\BillingCustomer;
use App\Models\Package;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Repository;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\SubscriptionProjector;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SubscriptionProjector: the one translation of merchant truth into the
 * local row, and the guards that make webhook disorder harmless.
 *
 * @see SubscriptionProjector
 */
class SubscriptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $repository = Repository::factory()->create(['path' => 'paid', 'public' => false]);
        $this->package = Package::factory()->create(['name' => 'acme/widgets', 'repository_id' => $repository->id]);

        $this->plan = Plan::factory()->create();
        $this->plan->entitlements()->create([
            'grantable_type' => Package::class,
            'grantable_id' => $this->package->getKey(),
        ]);
    }

    /**
     * @return array{0: Subscription, 1: User}
     */
    private function subscription(): array
    {
        $user = User::factory()->create();
        $customer = BillingCustomer::factory()->create([
            'billable_type' => $user->getMorphClass(),
            'billable_id' => $user->getKey(),
        ]);

        $subscription = Subscription::factory()->create([
            'billing_customer_id' => $customer->getKey(),
            'plan_id' => $this->plan->getKey(),
            'plan_price_id' => PlanPrice::factory()->create(['plan_id' => $this->plan->getKey()])->getKey(),
            'status' => SubscriptionStatus::Incomplete,
        ]);

        return [$subscription, $user];
    }

    private function remote(SubscriptionStatus $status, CarbonImmutable $asOf): RemoteSubscription
    {
        return new RemoteSubscription(
            externalId: 'sub_x',
            customerExternalId: 'cus_x',
            status: $status,
            priceExternalId: 'price_x',
            quantity: 1,
            trialEndsAt: null,
            currentPeriodStart: $asOf,
            currentPeriodEnd: $asOf->addMonth(),
            cancelAt: null,
            canceledAt: null,
            endedAt: null,
            couponCode: null,
            asOf: $asOf,
        );
    }

    private function reach(User $user): bool
    {
        return Package::query()->visibleToUser($user->fresh())->whereKey($this->package->getKey())->exists();
    }

    public function test_applying_merchant_truth_moves_the_row_and_the_grants(): void
    {
        [$subscription, $user] = $this->subscription();
        $projector = app(SubscriptionProjector::class);

        $projector->apply($subscription, $this->remote(SubscriptionStatus::Active, CarbonImmutable::now()));

        $this->assertSame(SubscriptionStatus::Active, $subscription->fresh()->status);
        $this->assertTrue($this->reach($user));
    }

    public function test_a_stale_event_cannot_roll_a_newer_truth_back(): void
    {
        [$subscription, $user] = $this->subscription();
        $projector = app(SubscriptionProjector::class);

        $now = CarbonImmutable::now();

        // The cancellation arrives first (it was sent second)...
        $projector->apply($subscription, $this->remote(SubscriptionStatus::Canceled, $now));
        $this->assertFalse($this->reach($user));

        // ...and the delayed "it became active" from a minute earlier must
        // not resurrect access.
        $projector->apply($subscription->fresh(), $this->remote(SubscriptionStatus::Active, $now->subMinute()));

        $this->assertSame(SubscriptionStatus::Canceled, $subscription->fresh()->status);
        $this->assertFalse($this->reach($user));
    }

    public function test_a_money_lapse_starts_the_plans_grace_clock_exactly_once(): void
    {
        $this->plan->forceFill(['grace_days' => 14])->save();

        [$subscription, $user] = $this->subscription();
        $projector = app(SubscriptionProjector::class);

        $now = CarbonImmutable::now();
        $projector->apply($subscription, $this->remote(SubscriptionStatus::Active, $now));

        // The merchant gives up collecting: grace begins, access continues.
        $projector->apply($subscription->fresh(), $this->remote(SubscriptionStatus::Unpaid, $now->addMinute()));

        $subscription->refresh();
        $this->assertNotNull($subscription->grace_ends_at);
        $firstDeadline = $subscription->grace_ends_at;
        $this->assertTrue($this->reach($user));

        // A second Unpaid event must not push the deadline out.
        $this->travel(1)->hours();
        $projector->apply($subscription->fresh(), $this->remote(SubscriptionStatus::Unpaid, $now->addHours(2)));
        $this->assertTrue($firstDeadline->equalTo($subscription->fresh()->grace_ends_at));

        // Renewal clears the clock.
        $projector->apply($subscription->fresh(), $this->remote(SubscriptionStatus::Active, $now->addHours(3)));
        $this->assertNull($subscription->fresh()->grace_ends_at);
    }

    public function test_an_incomplete_checkout_earns_no_grace(): void
    {
        $this->plan->forceFill(['grace_days' => 14])->save();

        [$subscription, $user] = $this->subscription();
        $projector = app(SubscriptionProjector::class);

        $projector->apply($subscription, $this->remote(SubscriptionStatus::Incomplete, CarbonImmutable::now()));

        $this->assertNull($subscription->fresh()->grace_ends_at);
        $this->assertFalse($this->reach($user));
    }
}
