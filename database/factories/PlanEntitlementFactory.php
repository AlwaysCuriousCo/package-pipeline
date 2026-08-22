<?php

namespace Database\Factories;

use App\Models\Package;
use App\Models\Plan;
use App\Models\PlanEntitlement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanEntitlement>
 */
class PlanEntitlementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'grantable_type' => Package::class,
            'grantable_id' => Package::factory(),
        ];
    }
}
