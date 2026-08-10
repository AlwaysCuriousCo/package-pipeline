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

    /**
     * A row synced before the `order` column existed carries a null until the
     * next sync backfills it. Where a null sorts on a descending order is not
     * settled by the standard — Postgres puts it first, MySQL and SQLite put
     * it last — so the served document states the placement instead of
     * inheriting it, and an unordered row never displaces the real newest
     * release at the top of the response.
     */
    public function test_metadata_sorts_unordered_releases_last(): void
    {
        $normalizer = new VersionNormalizer;
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        foreach (['0.1.0' => null, '1.0.0' => '1.0.0', '2.0.0' => '2.0.0'] as $version => $order) {
            $package->versions()->create([
                'version' => $version,
                'order' => $order === null ? null : $normalizer->order($order),
                'reference' => sha1($version),
                'is_dev' => false,
                'metadata' => ['name' => 'acme/widgets', 'version' => $version],
            ]);
        }

        $versions = $this->get('/p2/acme/widgets.json')
            ->assertOk()
            ->json('packages.acme/widgets');

        $this->assertSame(
            ['2.0.0', '1.0.0', '0.1.0'],
            array_column($versions, 'version'),
        );
    }

    /**
     * Two spellings of one release normalise to the same order string, so the
     * sort needs a tiebreak of its own — otherwise the bytes served for an
     * unchanged package depend on whatever the planner felt like returning,
     * while the ETag cut from those rows says nothing moved.
     */
    public function test_releases_sharing_an_order_string_are_broken_by_version(): void
    {
        $normalizer = new VersionNormalizer;
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        foreach (['v1.0.0', '1.0.0'] as $version) {
            $package->versions()->create([
                'version' => $version,
                'order' => $normalizer->order($version),
                'reference' => sha1($version),
                'is_dev' => false,
                'metadata' => ['name' => 'acme/widgets', 'version' => $version],
            ]);
        }

        $this->assertSame(
            1,
            $package->versions()->distinct()->count('order'),
            'The two spellings were expected to share one order string.',
        );

        $versions = $this->get('/p2/acme/widgets.json')
            ->assertOk()
            ->json('packages.acme/widgets');

        $this->assertSame(['v1.0.0', '1.0.0'], array_column($versions, 'version'));
    }
}
