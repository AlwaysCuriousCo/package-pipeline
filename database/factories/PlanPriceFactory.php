<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Models\Plan;
use App\Models\PlanPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanPrice>
 */
class PlanPriceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'currency' => 'usd',
            'amount' => fake()->numberBetween(5, 200) * 100,
            'interval' => BillingInterval::Month,
            'interval_count' => 1,
            'active' => true,
            'default' => true,
        ];
    }

    public function yearly(): static
    {
        return $this->state(fn (): array => ['interval' => BillingInterval::Year]);
    }

    public function oneTime(): static
    {
        return $this->state(fn (): array => ['interval' => BillingInterval::OneTime]);
    }
}
