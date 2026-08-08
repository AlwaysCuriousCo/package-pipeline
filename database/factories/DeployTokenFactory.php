<?php

namespace Database\Factories;

use App\Models\DeployToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeployToken>
 */
class DeployTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true).' deploy',
        ];
    }
}
