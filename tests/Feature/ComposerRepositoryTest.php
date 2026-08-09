<?php

namespace Tests\Feature;

use App\Models\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ComposerRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeServedPackage(): Package
    {
        $package = Package::factory()->create([
            'name' => 'acme/widgets',
            'repository' => 'https://github.com/acme/widgets',
            'token' => 'ghp_secret',
            'description' => 'Widgets for Acme.',
            'type' => 'library',
        ]);

        $package->versions()->create([
            'version' => 'v1.1.0',
            'reference' => str_repeat('b', 40),
            'is_dev' => false,
            'released_at' => '2026-02-01 12:00:00',
            'archive_path' => 'packages/acme/widgets/v110.zip',
            'shasum' => sha1('zip-bytes'),
            'metadata' => [
                'name' => 'acme/widgets',
                'version' => 'v1.1.0',
                'type' => 'library',
                'require' => ['php' => '^8.3'],
            ],
        ]);

        // dev-main predates archive storage: no archive_path, no shasum.
        $package->versions()->create([
            'version' => 'dev-main',
            'reference' => str_repeat('d', 40),
            'is_dev' => true,
            'metadata' => ['name' => 'acme/widgets', 'version' => 'dev-main'],
        ]);

        return $package;
    }

    private function makeServedPackageNamed(string $name, string $type = 'library'): Package
    {
        $package = Package::factory()->create(['name' => $name, 'type' => $type]);

        $package->versions()->create([
            'version' => 'v1.0.0',
            'reference' => str_repeat('a', 40),
            'is_dev' => false,
            'metadata' => ['name' => $name, 'version' => 'v1.0.0'],
        ]);

        return $package;
    }

    public function test_the_repository_root_advertises_metadata_search_and_list(): void
    {
        $this->makeServedPackage();

        $this->get('/packages.json')
            ->assertOk()
            ->assertJson([
                'metadata-url' => '/p2/%package%.json',
                'search' => url('/search.json').'?q=%query%&type=%type%',
                'list' => url('/list.json'),
            ])
            // Inlining every name would defeat Composer's lazy loading and
            // publish the package list to anyone fetching the root.
            ->assertJsonMissingPath('available-packages');
    }

    public function test_search_filters_by_name_prefix(): void
    {
        $this->makeServedPackage();
        $this->makeServedPackageNamed('acme/gadgets');
        $this->makeServedPackageNamed('other/widgets');

        $this->get('/search.json?q=acme/w')
            ->assertOk()
            ->assertExactJson([
                'total' => 1,
                'results' => [
                    [
                        'name' => 'acme/widgets',
                        'description' => 'Widgets for Acme.',
                        'downloads' => 0,
                    ],
                ],
            ]);
    }

    public function test_search_without_a_query_returns_every_served_package(): void
    {
        $this->makeServedPackage();
        $this->makeServedPackageNamed('other/widgets');
        Package::factory()->create(['name' => 'acme/unsynced']);

        $response = $this->get('/search.json')->assertOk();

        $this->assertSame(2, $response->json('total'));
        $this->assertSame(['acme/widgets', 'other/widgets'], $response->json('results.*.name'));
    }

    public function test_search_filters_by_package_type(): void
    {
        $this->makeServedPackage();
        $this->makeServedPackageNamed('acme/theme', type: 'wordpress-theme');

        $response = $this->get('/search.json?q=acme/&type=wordpress-theme')->assertOk();

        $this->assertSame(1, $response->json('total'));
        $this->assertSame('acme/theme', $response->json('results.0.name'));
    }

    public function test_search_treats_like_wildcards_as_literals(): void
    {
        // A query of "%" must not enumerate the registry.
        $this->makeServedPackage();

        $this->get('/search.json?q=%25')
            ->assertOk()
            ->assertExactJson(['total' => 0, 'results' => []]);
    }

    public function test_list_returns_served_package_names_sorted(): void
    {
        $this->makeServedPackage();
        $this->makeServedPackageNamed('aaa/first');
        Package::factory()->create(['name' => 'acme/unsynced']);

        $this->get('/list.json')
            ->assertOk()
            ->assertExactJson(['packageNames' => ['aaa/first', 'acme/widgets']]);
    }

    public function test_stable_metadata_lists_tagged_versions_with_local_dists(): void
    {
        $this->makeServedPackage();

        $response = $this->get('/p2/acme/widgets.json')->assertOk();

        $versions = $response->json('packages.acme/widgets');

        $this->assertCount(1, $versions);
        $this->assertSame('v1.1.0', $versions[0]['version']);
        $this->assertSame(['php' => '^8.3'], $versions[0]['require']);
        $this->assertSame('zip', $versions[0]['dist']['type']);
        $this->assertSame(str_repeat('b', 40), $versions[0]['dist']['reference']);
        $this->assertSame(sha1('zip-bytes'), $versions[0]['dist']['shasum']);
        $this->assertStringContainsString('/dist/acme/widgets/'.str_repeat('b', 40).'.zip', $versions[0]['dist']['url']);
        $this->assertSame('2026-02-01T12:00:00+00:00', $versions[0]['time']);
    }

    public function test_a_version_without_a_stored_archive_is_served_without_a_shasum(): void
    {
        // Composer treats the key as a checksum to enforce; advertising a null
        // would be worse than admitting there is none yet.
        $this->makeServedPackage();

        $versions = $this->get('/p2/acme/widgets~dev.json')->assertOk()->json('packages.acme/widgets');

        $this->assertArrayNotHasKey('shasum', $versions[0]['dist']);
    }

    public function test_a_version_with_no_recorded_date_is_served_without_a_time(): void
    {
        // `dev-main` in the fixture predates date tracking. Composer treats a
        // null `time` as malformed, so the key is left out entirely.
        $this->makeServedPackage();

        $versions = $this->get('/p2/acme/widgets~dev.json')->assertOk()->json('packages.acme/widgets');

        $this->assertArrayNotHasKey('time', $versions[0]);
    }

    public function test_dev_metadata_lists_branch_versions(): void
    {
        $this->makeServedPackage();

        $this->get('/p2/acme/widgets~dev.json')
            ->assertOk()
            ->assertJsonPath('packages.acme/widgets.0.version', 'dev-main')
            ->assertJsonMissing(['version' => 'v1.1.0']);
    }

    public function test_metadata_for_an_unknown_package_is_a_404(): void
    {
        $this->get('/p2/acme/missing.json')->assertNotFound();
    }

    public function test_the_dist_endpoint_serves_the_stored_archive(): void
    {
        // The dist disk is configurable so it can be pointed at object
        // storage; the endpoint must not assume a local one.
        config(['filesystems.dists' => 's3']);
        Storage::fake('s3');
        Storage::disk('s3')->put('packages/acme/widgets/v110.zip', 'zip-bytes');
        Http::fake();

        $this->makeServedPackage();
        $reference = str_repeat('b', 40);

        $response = $this->get("/dist/acme/widgets/{$reference}.zip")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip');

        $this->assertSame('zip-bytes', $response->streamedContent());

        // Serving is storage only. GitHub is never consulted, so consumers
        // need no GitHub credentials and an outage there changes nothing.
        Http::assertNothingSent();
    }

    public function test_a_version_without_a_stored_archive_is_a_404(): void
    {
        config(['filesystems.dists' => 's3']);
        Storage::fake('s3');

        // dev-main's row predates archive storage, so nothing is stored yet.
        $this->makeServedPackage();

        $this->get('/dist/acme/widgets/'.str_repeat('d', 40).'.zip')->assertNotFound();
    }

    public function test_an_archive_missing_from_the_disk_is_a_404(): void
    {
        // The row says stored, the disk disagrees — the next sync backfills
        // it; until then the honest answer is a 404, not a broken stream.
        config(['filesystems.dists' => 's3']);
        Storage::fake('s3');

        $this->makeServedPackage();

        $this->get('/dist/acme/widgets/'.str_repeat('b', 40).'.zip')->assertNotFound();
    }

    public function test_the_dist_endpoint_falls_back_to_a_sibling_row_for_the_same_commit(): void
    {
        // A tag and a branch can point at the same commit. When the first
        // row's file is gone from the disk, a sibling row's still-stored zip
        // must serve rather than 404ing on the first path checked.
        config(['filesystems.dists' => 's3']);
        Storage::fake('s3');

        // v1.1.0's archive_path points at a file that was never stored; only
        // the sibling branch row's archive exists on the disk.
        $package = $this->makeServedPackage();

        $package->versions()->create([
            'version' => 'dev-release',
            'reference' => str_repeat('b', 40),
            'is_dev' => true,
            'archive_path' => 'packages/acme/widgets/sibling.zip',
            'shasum' => sha1('sibling-bytes'),
            'metadata' => ['name' => 'acme/widgets', 'version' => 'dev-release'],
        ]);

        Storage::disk('s3')->put('packages/acme/widgets/sibling.zip', 'sibling-bytes');

        $response = $this->get('/dist/acme/widgets/'.str_repeat('b', 40).'.zip')->assertOk();

        $this->assertSame('sibling-bytes', $response->streamedContent());
    }

    public function test_the_dist_endpoint_rejects_unknown_references(): void
    {
        $this->makeServedPackage();

        $this->get('/dist/acme/widgets/'.str_repeat('9', 40).'.zip')->assertNotFound();
        $this->get('/dist/acme/missing/'.str_repeat('b', 40).'.zip')->assertNotFound();
    }
}
