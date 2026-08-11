<?php

namespace Database\Factories;

use App\Models\Package;
use App\Models\PackageVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PackageVersion>
 */
class PackageVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $version = 'v'.fake()->numberBetween(0, 9).'.'.fake()->numberBetween(0, 20).'.'.fake()->numberBetween(0, 30);

        return [
            'package_id' => Package::factory(),
            'version' => $version,
            'reference' => fake()->sha1(),
            'is_dev' => false,
            'released_at' => fake()->dateTimeBetween('-2 years'),
            'metadata' => fn (array $attributes): array => [
                // Read as a column rather than a model: a test that made up a
                // package_id, or deleted the row, still gets metadata instead
                // of a dereference of nothing.
                'name' => Package::query()->whereKey($attributes['package_id'])->value('name') ?? 'acme/widgets',
                'version' => $attributes['version'],
                'type' => 'library',
                'require' => ['php' => '^8.3'],
            ],
        ];
    }

    /**
     * Indicate that the version tracks a branch rather than a tag.
     */
    public function dev(string $branch = 'main'): static
    {
        return $this->state(fn (array $attributes): array => [
            'version' => "dev-{$branch}",
            'is_dev' => true,
        ]);
    }
}
