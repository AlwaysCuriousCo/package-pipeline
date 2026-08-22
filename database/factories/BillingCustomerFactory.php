<?php

namespace Database\Factories;

use App\Enums\MerchantProvider;
use App\Models\BillingCustomer;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BillingCustomer>
 */
class BillingCustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'billable_type' => User::class,
            'billable_id' => User::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'merchant' => MerchantProvider::Manual,
        ];
    }

    public function stripe(): static
    {
        return $this->state(fn (): array => [
            'merchant' => MerchantProvider::Stripe,
            'merchant_customer_id' => 'cus_'.fake()->unique()->lexify('??????????'),
        ]);
    }

    public function forTeam(): static
    {
        return $this->state(fn (): array => [
            'billable_type' => Team::class,
            'billable_id' => Team::factory(),
            'billing_contact_user_id' => User::factory(),
        ]);
    }
}
