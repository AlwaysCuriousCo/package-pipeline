<?php

namespace Database\Factories;

use App\Models\MirroredPackage;
use App\Models\Upstream;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MirroredPackage>
 */
class MirroredPackageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $payload = json_encode([
            'minified' => 'composer/2.0',
            'packages' => ['vendor/mirrored' => [['name' => 'vendor/mirrored', 'version' => '1.0.0']]],
        ], JSON_THROW_ON_ERROR);

        return [
            'upstream_id' => Upstream::factory(),
            'name' => 'vendor/mirrored',
            'is_dev' => false,
            'payload' => $payload,
            'digest' => hash('xxh128', $payload),
            'fetched_at' => now(),
            'changed_at' => now(),
            'used_at' => now(),
        ];
    }

    /**
     * A name the upstream does not have — the negative cache.
     */
    public function missing(): static
    {
        return $this->state(fn (array $attributes): array => [
            'payload' => null,
            'digest' => null,
            'changed_at' => null,
        ]);
    }

    /**
     * Cached long enough ago that the next request revalidates it.
     */
    public function stale(): static
    {
        return $this->state(fn (array $attributes): array => [
            'fetched_at' => now()->subDays(1),
        ]);
    }
}
