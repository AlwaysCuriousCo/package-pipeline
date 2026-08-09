<?php

namespace Database\Factories;

use App\Models\Package;
use App\Models\PackageAdvisory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PackageAdvisory>
 */
class PackageAdvisoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'package_id' => Package::factory(),
            'title' => fake()->sentence(),
            'affected_versions' => '<'.fake()->numberBetween(1, 9).'.'.fake()->numberBetween(0, 20).'.'.fake()->numberBetween(1, 30),
            'reported_at' => fake()->dateTimeBetween('-1 year'),
            'severity' => fake()->randomElement(['low', 'medium', 'high', 'critical']),
        ];
    }

    /**
     * Indicate that the advisory came from an external feed rather than from
     * an admin recording it in the panel.
     */
    public function fromSource(string $source): static
    {
        return $this->state(fn (array $attributes): array => [
            'source' => $source,
        ]);
    }
}
