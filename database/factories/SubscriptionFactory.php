<?php

namespace Database\Factories;

use App\Enums\MerchantProvider;
use App\Enums\SubscriptionStatus;
use App\Models\BillingCustomer;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $plan = Plan::factory();

        return [
            'billing_customer_id' => BillingCustomer::factory(),
            'plan_id' => $plan,
            'plan_price_id' => PlanPrice::factory()->for($plan),
            'merchant' => MerchantProvider::Manual,
            'status' => SubscriptionStatus::Active,
            'quantity' => 1,
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ];
    }

    public function status(SubscriptionStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function suspended(string $reason = 'Suspended in a test'): static
    {
        return $this->state(fn (): array => [
            'suspended_at' => now(),
            'suspension_reason' => $reason,
        ]);
    }
}
