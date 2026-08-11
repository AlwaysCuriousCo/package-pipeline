<?php

namespace Database\Factories;

use App\Models\Repository;
use App\Models\Upstream;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Upstream>
 */
class UpstreamFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repository_id' => Repository::factory(),
            'name' => 'packagist.org',
            'url' => 'https://repo.packagist.org',
            'enabled' => true,
            'position' => 0,
        ];
    }

    /**
     * Configured but switched off, which is how an operator pauses mirroring
     * without throwing the cache away.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'enabled' => false,
        ]);
    }
}
