<?php

namespace Database\Factories;

use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $vendor = fake()->unique()->domainWord();
        $name = fake()->unique()->slug(2);

        return [
            'repository' => "https://github.com/{$vendor}/{$name}",
            'latest_version' => 'v'.fake()->numberBetween(0, 9).'.'.fake()->numberBetween(0, 20).'.'.fake()->numberBetween(0, 30),
            'name' => "{$vendor}/{$name}",
            'description' => fake()->sentence(),
            'type' => fake()->randomElement(['library', 'package', 'plugin', 'theme']),
        ];
    }

    /**
     * Indicate that the package is published from a directory inside its
     * repository rather than from the root.
     */
    public function inSubdirectory(string $subdirectory = 'packages/widgets'): static
    {
        return $this->state(fn (array $attributes): array => [
            'subdirectory' => $subdirectory,
        ]);
    }

    /**
     * Indicate that the package has not been released yet.
     */
    public function unreleased(): static
    {
        return $this->state(fn (array $attributes): array => [
            'latest_version' => null,
        ]);
    }
}
