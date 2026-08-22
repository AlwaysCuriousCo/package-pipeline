<?php

namespace Database\Factories;

use App\Models\BillingCustomer;
use App\Models\Entitlement;
use App\Models\Package;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Entitlement>
 */
class EntitlementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'billing_customer_id' => BillingCustomer::factory(),
            'grantable_type' => Package::class,
            'grantable_id' => Package::factory(),
            'active' => true,
            'starts_at' => now()->subDay(),
        ];
    }
}
