<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Support\VersionNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The /p2 responses sort releases by the normalizer's order string — the
 * database-friendly fix for string sorts putting 1.10.0 below 1.9.0.
 */
class VersionOrderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata_sorts_releases_semantically(): void
    {
        $normalizer = new VersionNormalizer;
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        foreach (['1.9.0', '1.10.0', '1.10.0-RC1'] as $index => $version) {
            $package->versions()->create([
                'version' => $version,
                'order' => $normalizer->order($version),
                'reference' => sha1($version),
                'is_dev' => false,
                'metadata' => ['name' => 'acme/widgets', 'version' => $version],
            ]);
        }

        $versions = $this->get('/p2/acme/widgets.json')
            ->assertOk()
            ->json('packages.acme/widgets');

        $this->assertSame(
            ['1.10.0', '1.10.0-RC1', '1.9.0'],
            array_column($versions, 'version'),
        );
    }
}
