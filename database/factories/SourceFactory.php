<?php

namespace Database\Factories;

use App\Enums\SourceProvider;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $account = fake()->unique()->domainWord();

        return [
            'name' => ucfirst($account),
            'provider' => SourceProvider::Github,
            'account' => $account,
            'account_type' => 'Organization',
            'installation_id' => fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'metadata' => ['repository_selection' => 'selected', 'repository_count' => 3],
            'connected_at' => now(),
        ];
    }

    /**
     * A source that has been created but never connected to the provider.
     */
    public function disconnected(): static
    {
        return $this->state(fn (): array => [
            'installation_id' => null,
            'token' => null,
            'metadata' => null,
            'connected_at' => null,
        ]);
    }

    /**
     * A source authenticating with a token typed in by hand rather than a
     * GitHub App installation.
     */
    public function withToken(string $token = 'github_pat_source'): static
    {
        return $this->state(fn (): array => [
            'installation_id' => null,
            'token' => $token,
        ]);
    }
}
