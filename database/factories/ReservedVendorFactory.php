<?php

namespace Database\Factories;

use App\Models\Repository;
use App\Models\ReservedVendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReservedVendor>
 */
class ReservedVendorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repository_id' => Repository::factory(),
            'vendor' => fake()->unique()->domainWord(),
        ];
    }
}
