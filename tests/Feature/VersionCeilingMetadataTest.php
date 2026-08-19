<?php

namespace Tests\Feature;

use App\Enums\SubscriptionStatus;
use App\Enums\TokenAbility;
use App\Models\BillingCustomer;
use App\Models\Package;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Repository;
use App\Models\Subscription;
use App\Models\Token;
use App\Models\User;
use App\Services\Billing\EntitlementProjector;
use App\Services\Billing\VersionCeiling;
use App\Support\VersionNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A frozen entitlement's version ceiling, enforced where Composer reads.
 *
 * Two promises, and the second matters as much as the first: a ceilinged
 * client sees only the versions released while their licence was live (and a
 * distinct validator, so caches cannot cross the streams), and everybody
 * else's response — body, ETag, cache entry — is byte-identical to what it
 * was before billing existed.
 *
 * @see VersionCeiling
 */
class VersionCeilingMetadataTest extends TestCase
{
    use RefreshDatabase;

    private Repository $paid;

    private Package $widgets;

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paid = Repository::factory()->create(['path' => 'paid', 'public' => false]);
        $this->widgets = Package::factory()->create(['name' => 'acme/widgets', 'repository_id' => $this->paid->id]);

        foreach (['1.0.0', '1.1.0'] as $version) {
            $this->makeVersion($version);
        }

        $this->widgets->versions()->create([
            'version' => 'dev-main',
            'reference' => sha1('dev-main'),
            'is_dev' => true,
            'metadata' => ['name' => 'acme/widgets', 'version' => 'dev-main'],
        ]);

        $this->plan = Plan::factory()->perpetual()->create();
        $this->plan->entitlements()->create([
            'grantable_type' => Package::class,
            'grantable_id' => $this->widgets->getKey(),
        ]);
    }

    private function makeVersion(string $version): void
    {
        // `order` the way the sync path writes it — the metadata filter
        // reads it, and a row without one sorts outside every ceiling.
        $this->widgets->versions()->create([
            'version' => $version,
            'order' => (new VersionNormalizer)->order($version),
            'reference' => sha1($version),
            'is_dev' => false,
            'metadata' => ['name' => 'acme/widgets', 'version' => $version],
        ]);
    }

    /**
     * @return array{0: Subscription, 1: string} the subscription and a plain token
     */
    private function subscribe(User $user): array
    {
        $customer = BillingCustomer::factory()->create([
            'billable_type' => $user->getMorphClass(),
            'billable_id' => $user->getKey(),
        ]);

        $subscription = Subscription::factory()->create([
            'billing_customer_id' => $customer->getKey(),
            'plan_id' => $this->plan->getKey(),
            'plan_price_id' => PlanPrice::factory()->create(['plan_id' => $this->plan->getKey()])->getKey(),
        ]);

        (new EntitlementProjector)->project($subscription);

        $token = Token::issue($user, 'test', [TokenAbility::RepositoryRead]);

        return [$subscription, $token->plainText];
    }

    /**
     * @return list<string>
     */
    private function versionsSeen(string $plainToken): array
    {
        $packages = $this->withBasicAuth('token', $plainToken)
            ->getJson('/r/paid/p2/acme/widgets.json')
            ->assertOk()
            ->json('packages.acme/widgets');

        return array_column($packages, 'version');
    }

    public function test_a_live_subscription_sees_every_version(): void
    {
        [, $plain] = $this->subscribe(User::factory()->create());

        $this->assertSame(['1.1.0', '1.0.0'], $this->versionsSeen($plain));
    }

    public function test_a_frozen_licence_sees_only_what_existed_at_the_freeze(): void
    {
        [$subscription, $plain] = $this->subscribe(User::factory()->create());

        $subscription->forceFill(['status' => SubscriptionStatus::Expired])->save();
        (new EntitlementProjector)->project($subscription);

        // Released after the freeze: outside the ceiling.
        $this->makeVersion('2.0.0');

        $this->assertSame(['1.1.0', '1.0.0'], $this->versionsSeen($plain));

        // The dev flavour empties out entirely — branches track the ongoing
        // work the licence stopped paying for.
        $dev = $this->withBasicAuth('token', $plain)
            ->getJson('/r/paid/p2/acme/widgets~dev.json')
            ->assertOk()
            ->json('packages.acme/widgets');

        $this->assertSame([], $dev);
    }

    public function test_the_ceiling_guards_the_dist_download_too(): void
    {
        [$subscription, $plain] = $this->subscribe(User::factory()->create());

        $subscription->forceFill(['status' => SubscriptionStatus::Expired])->save();
        (new EntitlementProjector)->project($subscription);

        $this->makeVersion('2.0.0');

        $this->withBasicAuth('token', $plain)
            ->get('/r/paid/dist/acme/widgets/'.sha1('2.0.0').'.zip')
            ->assertForbidden();
    }

    public function test_ceilinged_and_uncapped_clients_get_distinct_validators(): void
    {
        [$frozen, $frozenPlain] = $this->subscribe(User::factory()->create());
        [, $livePlain] = $this->subscribe(User::factory()->create());

        $frozen->forceFill(['status' => SubscriptionStatus::Expired])->save();
        (new EntitlementProjector)->project($frozen);

        $this->makeVersion('2.0.0');

        $frozenEtag = $this->withBasicAuth('token', $frozenPlain)
            ->getJson('/r/paid/p2/acme/widgets.json')->assertOk()->headers->get('ETag');
        $liveEtag = $this->withBasicAuth('token', $livePlain)
            ->getJson('/r/paid/p2/acme/widgets.json')->assertOk()->headers->get('ETag');

        $this->assertNotSame($frozenEtag, $liveEtag);
    }

    public function test_a_manual_grant_on_the_same_package_lifts_the_ceiling(): void
    {
        $user = User::factory()->create();
        [$subscription, $plain] = $this->subscribe($user);

        $subscription->forceFill(['status' => SubscriptionStatus::Expired])->save();
        (new EntitlementProjector)->project($subscription);

        $this->makeVersion('2.0.0');
        $this->assertSame(['1.1.0', '1.0.0'], $this->versionsSeen($plain));

        // An administrator grants the package by hand: the wider path wins.
        $user->packages()->attach($this->widgets);
        $this->assertSame(['2.0.0', '1.1.0', '1.0.0'], $this->versionsSeen($plain));
    }

    public function test_an_uncapped_readers_response_is_untouched_by_billing_existing(): void
    {
        // The manually-granted reader everyone was before billing.
        $reader = User::factory()->create();
        $reader->packages()->attach($this->widgets);
        $readerToken = Token::issue($reader, 'reader', [TokenAbility::RepositoryRead]);

        $before = $this->withBasicAuth('token', $readerToken->plainText)
            ->getJson('/r/paid/p2/acme/widgets.json')->assertOk();

        // A frozen licence appears in the world; the reader's document and
        // validator must not move.
        [$frozen] = $this->subscribe(User::factory()->create());
        $frozen->forceFill(['status' => SubscriptionStatus::Expired])->save();
        (new EntitlementProjector)->project($frozen);

        $after = $this->withBasicAuth('token', $readerToken->plainText)
            ->getJson('/r/paid/p2/acme/widgets.json')->assertOk();

        $this->assertSame($before->headers->get('ETag'), $after->headers->get('ETag'));
        $this->assertSame($before->getContent(), $after->getContent());
    }
}
