<?php

namespace Database\Factories;

use App\Enums\AuthProvider;
use App\Models\AuthenticationSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthenticationSource>
 */
class AuthenticationSourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'provider' => AuthProvider::Github,
            'client_id' => fake()->uuid(),
            'client_secret' => fake()->sha256(),
            'active' => true,
            'allow_registration' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['active' => false]);
    }
}
