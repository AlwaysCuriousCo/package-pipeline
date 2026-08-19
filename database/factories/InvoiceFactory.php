<?php

namespace Database\Factories;

use App\Enums\MerchantProvider;
use App\Models\BillingCustomer;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->numberBetween(5, 200) * 100;

        return [
            'billing_customer_id' => BillingCustomer::factory(),
            'merchant' => MerchantProvider::Stripe,
            'number' => strtoupper(fake()->unique()->bothify('INV-####-????')),
            'currency' => 'usd',
            'subtotal' => $subtotal,
            'tax' => 0,
            'total' => $subtotal,
            'amount_refunded' => 0,
            'status' => 'paid',
            'issued_at' => now(),
            'paid_at' => now(),
        ];
    }
}
