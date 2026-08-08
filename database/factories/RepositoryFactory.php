<?php

namespace Database\Factories;

use App\Models\Repository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Repository>
 */
class RepositoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $path = fake()->unique()->slug(2);

        return [
            'name' => ucfirst(str_replace('-', ' ', $path)),
            'path' => $path,
            'description' => fake()->sentence(),
            'public' => false,
        ];
    }

    /**
     * Indicate that the repository is readable without a token.
     */
    public function public(): static
    {
        return $this->state(fn (array $attributes): array => [
            'public' => true,
        ]);
    }
}
