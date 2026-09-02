<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sponsor section on a package's public page: shown only while there is a
 * purchasable plan behind it, offering every active price — one-time and
 * recurring alike — through the ordinary checkout.
 */
class PackageSponsorshipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['registry.billing.enabled' => true]);
    }

    private function package(?Plan $plan): Package
    {
        return Package::factory()->create([
            'name' => 'acme/widgets',
            'repository' => 'https://github.com/acme/widgets',
            'page_enabled' => true,
            'sponsor_plan_id' => $plan?->getKey(),
        ]);
    }

    public function test_the_page_offers_every_active_price_of_the_sponsor_plan(): void
    {
        $plan = Plan::factory()->create();
        PlanPrice::factory()->create(['plan_id' => $plan->getKey(), 'amount' => 500]);
        PlanPrice::factory()->oneTime()->create(['plan_id' => $plan->getKey(), 'amount' => 2500, 'default' => false]);
        PlanPrice::factory()->create(['plan_id' => $plan->getKey(), 'amount' => 9900, 'active' => false, 'default' => false]);

        $this->package($plan);

        $response = $this->get('/p/acme/widgets');

        $response->assertOk()
            ->assertSee('Sponsor this package')
            ->assertSee('USD 5.00/month')
            ->assertSee('USD 25.00')
            ->assertDontSee('USD 99.00');
    }

    public function test_no_sponsor_section_without_a_plan(): void
    {
        $this->package(null);

        $this->get('/p/acme/widgets')->assertOk()->assertDontSee('Sponsor this package');
    }

    public function test_no_sponsor_section_when_the_plan_is_not_purchasable(): void
    {
        $this->package(Plan::factory()->create());

        $this->get('/p/acme/widgets')->assertOk()->assertDontSee('Sponsor this package');
    }

    public function test_no_sponsor_section_while_billing_is_disabled(): void
    {
        config(['registry.billing.enabled' => false]);

        $plan = Plan::factory()->create();
        PlanPrice::factory()->create(['plan_id' => $plan->getKey()]);
        $this->package($plan);

        $this->get('/p/acme/widgets')->assertOk()->assertDontSee('Sponsor this package');
    }
}
