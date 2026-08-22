<?php

namespace Tests\Feature;

use App\Enums\GrantSource;
use App\Enums\LapseBehaviour;
use App\Enums\SubscriptionStatus;
use App\Enums\TokenAbility;
use App\Models\BillingCustomer;
use App\Models\Package;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Repository;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\Token;
use App\Models\User;
use App\Services\Billing\EntitlementProjector;
use App\Services\Billing\SubscriptionTokens;
use App\Services\Billing\VersionCeiling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Subscriptions grant access by projection into the grant pivots, and the
 * projector is the one writer of every row whose source says 'subscription'.
 *
 * These are the promises the whole commercial layer stands on: an active
 * subscription makes its plan's packages visible through the same chokepoint
 * every read already uses; lapsing withdraws exactly what the plan's lapse
 * behaviour says and nothing else; a manual grant on the same package is
 * never disturbed in either direction; and running the projector twice is
 * running it once.
 *
 * @see EntitlementProjector
 * @see docs/plans/ecommerce-subscriptions.md
 */
class BillingEntitlementScopingTest extends TestCase
{
    use RefreshDatabase;

    private Repository $paid;

    private Package $widgets;

    private Package $gadgets;

    private Package $unrelated;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paid = Repository::factory()->create(['path' => 'paid', 'public' => false]);
        $other = Repository::factory()->create(['path' => 'other', 'public' => false]);

        $this->widgets = $this->makePackage('acme/widgets', $this->paid, ['1.0.0', '1.1.0']);
        $this->gadgets = $this->makePackage('acme/gadgets', $this->paid, ['2.0.0']);
        $this->unrelated = $this->makePackage('acme/unrelated', $other, ['1.0.0']);

        $this->plan = Plan::factory()->create();
        $this->plan->entitlements()->create([
            'grantable_type' => Package::class,
            'grantable_id' => $this->widgets->getKey(),
        ]);
    }

    /**
     * @param  list<string>  $versions
     */
    private function makePackage(string $name, Repository $repository, array $versions): Package
    {
        $package = Package::factory()->create(['name' => $name, 'repository_id' => $repository->id]);

        foreach ($versions as $version) {
            $package->versions()->create([
                'version' => $version,
                'reference' => sha1($name.$version),
                'is_dev' => false,
                'metadata' => ['name' => $name, 'version' => $version],
            ]);
        }

        return $package;
    }

    private function subscribe(User|Team $billable, ?Plan $plan = null, SubscriptionStatus $status = SubscriptionStatus::Active): Subscription
    {
        $plan ??= $this->plan;

        $customer = BillingCustomer::factory()->create([
            'billable_type' => $billable->getMorphClass(),
            'billable_id' => $billable->getKey(),
        ]);

        return Subscription::factory()
            ->status($status)
            ->create([
                'billing_customer_id' => $customer->getKey(),
                'plan_id' => $plan->getKey(),
                'plan_price_id' => PlanPrice::factory()->create(['plan_id' => $plan->getKey()])->getKey(),
            ]);
    }

    /**
     * @return list<string>
     */
    private function reach(User $user): array
    {
        return Package::query()->visibleToUser($user)->orderBy('name')->pluck('name')->all();
    }

    public function test_an_active_subscription_grants_the_plans_packages(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user);

        (new EntitlementProjector)->project($subscription);

        $this->assertSame(['acme/widgets'], $this->reach($user));
        $this->assertDatabaseHas('package_user', [
            'package_id' => $this->widgets->getKey(),
            'user_id' => $user->getKey(),
            'source' => GrantSource::Subscription->value,
        ]);
    }

    public function test_a_repository_entitlement_grants_the_whole_repository(): void
    {
        $plan = Plan::factory()->create();
        $plan->entitlements()->create([
            'grantable_type' => Repository::class,
            'grantable_id' => $this->paid->getKey(),
        ]);

        $user = User::factory()->create();
        (new EntitlementProjector)->project($this->subscribe($user, $plan));

        $this->assertSame(['acme/gadgets', 'acme/widgets'], $this->reach($user));
    }

    public function test_lapsing_withdraws_the_entitlement_and_only_the_entitlement(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user);
        $projector = new EntitlementProjector;

        $projector->project($subscription);
        $this->assertSame(['acme/widgets'], $this->reach($user));

        $subscription->forceFill(['status' => SubscriptionStatus::Canceled])->save();
        $projector->project($subscription);

        $this->assertSame([], $this->reach($user));
    }

    public function test_a_manual_grant_on_the_same_package_survives_the_lapse(): void
    {
        $user = User::factory()->create();
        $user->packages()->attach($this->widgets);

        $subscription = $this->subscribe($user);
        $projector = new EntitlementProjector;

        $projector->project($subscription);

        // Granted twice — by hand and by subscription — and visible once.
        $this->assertSame(['acme/widgets'], $this->reach($user));
        $this->assertSame(2, DB::table('package_user')->where('user_id', $user->getKey())->count());

        $subscription->forceFill(['status' => SubscriptionStatus::Canceled])->save();
        $projector->project($subscription);

        $this->assertSame(['acme/widgets'], $this->reach($user));
        $this->assertDatabaseHas('package_user', [
            'package_id' => $this->widgets->getKey(),
            'user_id' => $user->getKey(),
            'source' => GrantSource::Manual->value,
        ]);
    }

    public function test_the_projector_never_touches_manual_grants_going_the_other_way(): void
    {
        $user = User::factory()->create();
        $user->packages()->attach($this->unrelated);

        (new EntitlementProjector)->project($this->subscribe($user));

        $this->assertSame(['acme/unrelated', 'acme/widgets'], $this->reach($user));
    }

    public function test_a_team_subscription_grants_to_every_member_through_the_team(): void
    {
        $team = Team::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $team->users()->attach($member);

        (new EntitlementProjector)->project($this->subscribe($team));

        $this->assertSame(['acme/widgets'], $this->reach($member));
        $this->assertSame([], $this->reach($outsider));

        // Membership churn behaves exactly as manual team grants do.
        $team->users()->detach($member);
        $this->assertSame([], $this->reach($member->fresh()));
    }

    public function test_suspension_withdraws_access_even_when_lapse_would_keep_it(): void
    {
        $plan = Plan::factory()->create(['lapse_behaviour' => LapseBehaviour::None]);
        $plan->entitlements()->create([
            'grantable_type' => Package::class,
            'grantable_id' => $this->widgets->getKey(),
        ]);

        $user = User::factory()->create();
        $subscription = $this->subscribe($user, $plan);
        $projector = new EntitlementProjector;

        $projector->project($subscription);
        $this->assertSame(['acme/widgets'], $this->reach($user));

        $subscription->forceFill(['suspended_at' => now(), 'suspension_reason' => 'dispute'])->save();
        $projector->project($subscription);

        $this->assertSame([], $this->reach($user));

        // Unsuspending restores everything.
        $subscription->forceFill(['suspended_at' => null, 'suspension_reason' => null])->save();
        $projector->project($subscription);

        $this->assertSame(['acme/widgets'], $this->reach($user));
    }

    public function test_lapse_behaviour_none_keeps_the_grants(): void
    {
        $plan = Plan::factory()->create(['lapse_behaviour' => LapseBehaviour::None]);
        $plan->entitlements()->create([
            'grantable_type' => Package::class,
            'grantable_id' => $this->widgets->getKey(),
        ]);

        $user = User::factory()->create();
        $subscription = $this->subscribe($user, $plan);
        $projector = new EntitlementProjector;

        $projector->project($subscription);
        $subscription->forceFill(['status' => SubscriptionStatus::Canceled])->save();
        $projector->project($subscription);

        $this->assertSame(['acme/widgets'], $this->reach($user));
    }

    public function test_revoke_tokens_revokes_the_subscriptions_tokens_and_no_others(): void
    {
        $plan = Plan::factory()->create(['lapse_behaviour' => LapseBehaviour::RevokeTokens]);
        $plan->entitlements()->create([
            'grantable_type' => Package::class,
            'grantable_id' => $this->widgets->getKey(),
        ]);

        $user = User::factory()->create();
        $subscription = $this->subscribe($user, $plan);
        $projector = new EntitlementProjector;
        $projector->project($subscription);

        $subscriptionToken = (new SubscriptionTokens)->issueActivationToken($subscription);
        $personal = Token::issue($user, 'laptop', [TokenAbility::RepositoryRead]);

        $this->assertNotNull($subscriptionToken);

        $subscription->forceFill(['status' => SubscriptionStatus::Unpaid])->save();
        $projector->project($subscription);

        $this->assertNull(Token::findByPlainText($subscriptionToken->plainText));
        $this->assertNotNull(Token::findByPlainText($personal->plainText));
        $this->assertSame([], $this->reach($user));
    }

    public function test_freeze_at_version_pins_a_ceiling_and_keeps_the_grant(): void
    {
        $plan = Plan::factory()->perpetual()->create();
        $plan->entitlements()->create([
            'grantable_type' => Package::class,
            'grantable_id' => $this->widgets->getKey(),
        ]);

        $user = User::factory()->create();
        $subscription = $this->subscribe($user, $plan);
        $projector = new EntitlementProjector;

        $projector->project($subscription);
        $subscription->forceFill(['status' => SubscriptionStatus::Expired])->save();
        $projector->project($subscription);

        // Still granted — the licence is perpetual — but pinned.
        $this->assertSame(['acme/widgets'], $this->reach($user));

        $entitlement = $subscription->entitlements()
            ->where('grantable_type', Package::class)
            ->where('grantable_id', $this->widgets->getKey())
            ->sole();

        $this->assertTrue((bool) $entitlement->active);
        $this->assertNotNull($entitlement->version_ceiling);

        // The pin is the highest version that existed at the freeze: 1.1.0
        // is inside it, and a release cut afterwards is not.
        $ceilings = new VersionCeiling;
        $onePointOne = $this->widgets->versions()->where('version', '1.1.0')->sole();
        $this->assertTrue($ceilings->permits($onePointOne, $entitlement->version_ceiling));

        $newer = $this->widgets->versions()->create([
            'version' => '2.0.0',
            'reference' => sha1('acme/widgets2.0.0'),
            'is_dev' => false,
            'metadata' => ['name' => 'acme/widgets', 'version' => '2.0.0'],
        ]);
        $this->assertFalse($ceilings->permits($newer, $entitlement->version_ceiling));

        // A second projection must not move the pin.
        $before = $entitlement->version_ceiling;
        $projector->project($subscription);
        $this->assertSame($before, $entitlement->fresh()->version_ceiling);
    }

    public function test_a_frozen_repository_grant_expands_to_the_packages_it_held(): void
    {
        $plan = Plan::factory()->perpetual()->create();
        $plan->entitlements()->create([
            'grantable_type' => Repository::class,
            'grantable_id' => $this->paid->getKey(),
        ]);

        $user = User::factory()->create();
        $subscription = $this->subscribe($user, $plan);
        $projector = new EntitlementProjector;

        $projector->project($subscription);
        $this->assertSame(['acme/gadgets', 'acme/widgets'], $this->reach($user));

        $subscription->forceFill(['status' => SubscriptionStatus::Expired])->save();
        $projector->project($subscription);

        // Both packages stay reachable, each behind its own ceiling; the
        // repository-wide grant is gone, so a package added to the
        // repository after the freeze is never granted.
        $this->assertSame(['acme/gadgets', 'acme/widgets'], $this->reach($user));

        $latecomer = $this->makePackage('acme/latecomer', $this->paid, ['1.0.0']);
        $projector->project($subscription);

        $this->assertSame(['acme/gadgets', 'acme/widgets'], $this->reach($user));
        $this->assertNotContains($latecomer->name, $this->reach($user));

        // Renewal restores the repository grant and retires the expansion.
        $subscription->forceFill(['status' => SubscriptionStatus::Active])->save();
        $projector->project($subscription);

        $this->assertSame(['acme/gadgets', 'acme/latecomer', 'acme/widgets'], $this->reach($user));
        $this->assertNull(
            $subscription->entitlements()
                ->where('grantable_type', Repository::class)
                ->sole()
                ->version_ceiling,
        );
    }

    public function test_grace_keeps_access_until_it_runs_out(): void
    {
        $plan = Plan::factory()->create(['grace_days' => 14]);
        $plan->entitlements()->create([
            'grantable_type' => Package::class,
            'grantable_id' => $this->widgets->getKey(),
        ]);

        $user = User::factory()->create();
        $subscription = $this->subscribe($user, $plan);
        $projector = new EntitlementProjector;
        $projector->project($subscription);

        // The merchant gave up, but the plan's grace clock is still running.
        $subscription->forceFill([
            'status' => SubscriptionStatus::Unpaid,
            'grace_ends_at' => now()->addDays(14),
        ])->save();
        $projector->project($subscription);

        $this->assertSame(['acme/widgets'], $this->reach($user));

        // The clock ran out.
        $subscription->forceFill(['grace_ends_at' => now()->subMinute()])->save();
        $projector->project($subscription);

        $this->assertSame([], $this->reach($user));
    }

    public function test_two_subscriptions_granting_one_package_withdraw_independently(): void
    {
        $user = User::factory()->create();

        $first = $this->subscribe($user);

        $secondPlan = Plan::factory()->create();
        $secondPlan->entitlements()->create([
            'grantable_type' => Package::class,
            'grantable_id' => $this->widgets->getKey(),
        ]);
        $customer = $first->customer;
        $second = Subscription::factory()->create([
            'billing_customer_id' => $customer->getKey(),
            'plan_id' => $secondPlan->getKey(),
            'plan_price_id' => PlanPrice::factory()->create(['plan_id' => $secondPlan->getKey()])->getKey(),
        ]);

        $projector = new EntitlementProjector;
        $projector->projectCustomer($customer);
        $this->assertSame(['acme/widgets'], $this->reach($user));

        // One lapses; the other still holds the grant.
        $first->forceFill(['status' => SubscriptionStatus::Canceled])->save();
        $projector->projectCustomer($customer->fresh());
        $this->assertSame(['acme/widgets'], $this->reach($user));

        // Both lapsed; the grant goes.
        $second->forceFill(['status' => SubscriptionStatus::Canceled])->save();
        $projector->projectCustomer($customer->fresh());
        $this->assertSame([], $this->reach($user));
    }

    public function test_editing_the_plan_reprojects_onto_active_subscriptions(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user);
        $projector = new EntitlementProjector;
        $projector->project($subscription);

        $this->plan->entitlements()->create([
            'grantable_type' => Package::class,
            'grantable_id' => $this->gadgets->getKey(),
        ]);
        $projector->project($subscription->fresh());

        $this->assertSame(['acme/gadgets', 'acme/widgets'], $this->reach($user));

        $this->plan->entitlements()->where('grantable_id', $this->gadgets->getKey())->delete();
        $projector->project($subscription->fresh());

        $this->assertSame(['acme/widgets'], $this->reach($user));
    }

    public function test_projection_is_idempotent(): void
    {
        $user = User::factory()->create();
        $subscription = $this->subscribe($user);
        $projector = new EntitlementProjector;

        $projector->project($subscription);
        $rows = DB::table('package_user')->where('user_id', $user->getKey())->count();

        $projector->project($subscription);
        $projector->project($subscription);

        $this->assertSame($rows, DB::table('package_user')->where('user_id', $user->getKey())->count());
        $this->assertSame(['acme/widgets'], $this->reach($user));
    }

    public function test_the_token_limit_caps_issuance_and_revocation_frees_a_seat(): void
    {
        $plan = Plan::factory()->create(['token_limit' => 2]);
        $plan->entitlements()->create([
            'grantable_type' => Package::class,
            'grantable_id' => $this->widgets->getKey(),
        ]);

        $user = User::factory()->create();
        $subscription = $this->subscribe($user, $plan);
        $tokens = new SubscriptionTokens;

        $first = $tokens->issueActivationToken($subscription);
        $second = $tokens->issueFor($subscription, 'ci');
        $third = $tokens->issueFor($subscription, 'one too many');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNull($third);

        // Revoking one frees its seat.
        $first->token->delete();
        $this->assertNotNull($tokens->issueFor($subscription, 'replacement'));
    }
}
