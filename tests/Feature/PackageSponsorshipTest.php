<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sponsor section on a package's public page: one tier per attached plan,
 * each offering its active prices — one-time and recurring alike — through
 * the ordinary checkout, and shown only while somebody could actually buy it.
 */
class PackageSponsorshipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['registry.billing.enabled' => true]);
    }

    /**
     * @param  list<Plan>  $tiers
     */
    private function package(array $tiers = []): Package
    {
        $package = Package::factory()->create([
            'name' => 'acme/widgets',
            'repository' => 'https://github.com/acme/widgets',
            'page_enabled' => true,
        ]);

        $package->sponsorPlans()->attach(array_map(fn (Plan $tier) => $tier->getKey(), $tiers));

        return $package;
    }

    private function tier(string $name, int $sort = 0): Plan
    {
        return Plan::factory()->create(['name' => $name, 'sort' => $sort]);
    }

    public function test_each_tier_offers_its_active_prices_in_sort_order(): void
    {
        $supporter = $this->tier('Supporter', 1);
        PlanPrice::factory()->create(['plan_id' => $supporter->getKey(), 'amount' => 500]);
        PlanPrice::factory()->oneTime()->create(['plan_id' => $supporter->getKey(), 'amount' => 2500, 'default' => false]);
        PlanPrice::factory()->create(['plan_id' => $supporter->getKey(), 'amount' => 9900, 'active' => false, 'default' => false]);

        $gold = $this->tier('Gold sponsor', 2);
        PlanPrice::factory()->create(['plan_id' => $gold->getKey(), 'amount' => 10000]);

        $this->package([$gold, $supporter]);

        $response = $this->get('/p/acme/widgets');

        $response->assertOk()
            ->assertSee('Sponsor this package')
            ->assertSee('USD 5.00/month')
            ->assertSee('USD 25.00')
            ->assertDontSee('USD 99.00')
            // The plans' own sort order, not attachment order.
            ->assertSeeInOrder(['Supporter', 'Gold sponsor']);
    }

    public function test_a_tier_links_to_the_plan_page_that_explains_its_perks(): void
    {
        $tier = $this->tier('Gold sponsor');
        PlanPrice::factory()->create(['plan_id' => $tier->getKey()]);

        $this->package([$tier]);

        $this->get('/p/acme/widgets')
            ->assertOk()
            ->assertSee(route('pages.pricing.plan', $tier));
    }

    public function test_no_sponsor_section_without_tiers(): void
    {
        $this->package();

        $this->get('/p/acme/widgets')->assertOk()->assertDontSee('Sponsor this package');
    }

    public function test_a_tier_nobody_could_buy_is_left_out(): void
    {
        $priceless = $this->tier('Priceless');
        $inactive = $this->tier('Retired');
        $inactive->update(['active' => false]);
        PlanPrice::factory()->create(['plan_id' => $inactive->getKey()]);

        $this->package([$priceless, $inactive]);

        $this->get('/p/acme/widgets')->assertOk()->assertDontSee('Sponsor this package');
    }

    public function test_no_sponsor_section_while_billing_is_disabled(): void
    {
        config(['registry.billing.enabled' => false]);

        $tier = $this->tier('Supporter');
        PlanPrice::factory()->create(['plan_id' => $tier->getKey()]);
        $this->package([$tier]);

        $this->get('/p/acme/widgets')->assertOk()->assertDontSee('Sponsor this package');
    }
}
