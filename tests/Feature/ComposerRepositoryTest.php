<?php

namespace Tests\Feature;

use App\Models\Package;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
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
        ]);

        $package->versions()->create([
            'version' => 'v1.1.0',
            'reference' => str_repeat('b', 40),
            'is_dev' => false,
            'released_at' => '2026-02-01 12:00:00',
            'metadata' => [
                'name' => 'acme/widgets',
                'version' => 'v1.1.0',
                'type' => 'library',
                'require' => ['php' => '^8.3'],
            ],
        ]);

        $package->versions()->create([
            'version' => 'dev-main',
            'reference' => str_repeat('d', 40),
            'is_dev' => true,
            'metadata' => ['name' => 'acme/widgets', 'version' => 'dev-main'],
        ]);

        return $package;
    }

    public function test_the_repository_root_advertises_metadata_and_packages(): void
    {
        $this->makeServedPackage();
        Package::factory()->create(['name' => 'acme/unsynced']);

        $this->get('/packages.json')
            ->assertOk()
            ->assertJson([
                'metadata-url' => '/p2/%package%.json',
                'available-packages' => ['acme/widgets'],
            ]);
    }

    public function test_stable_metadata_lists_tagged_versions_with_proxied_dists(): void
    {
        $this->makeServedPackage();

        $response = $this->get('/p2/acme/widgets.json')->assertOk();

        $versions = $response->json('packages.acme/widgets');

        $this->assertCount(1, $versions);
        $this->assertSame('v1.1.0', $versions[0]['version']);
        $this->assertSame(['php' => '^8.3'], $versions[0]['require']);
        $this->assertSame('zip', $versions[0]['dist']['type']);
        $this->assertSame(str_repeat('b', 40), $versions[0]['dist']['reference']);
        $this->assertStringContainsString('/dist/acme/widgets/'.str_repeat('b', 40).'.zip', $versions[0]['dist']['url']);
        $this->assertSame('2026-02-01T12:00:00+00:00', $versions[0]['time']);
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

    public function test_the_dist_endpoint_proxies_and_caches_the_zipball(): void
    {
        // The dist disk is configurable so it can be pointed at object
        // storage; the endpoint must not assume a local one.
        config(['filesystems.dists' => 's3']);
        Storage::fake('s3');
        Http::fake([
            'api.github.com/repos/acme/widgets/zipball/*' => Http::response('zip-bytes'),
        ]);

        $this->makeServedPackage();
        $reference = str_repeat('b', 40);

        $response = $this->get("/dist/acme/widgets/{$reference}.zip")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip');

        $this->assertSame('zip-bytes', $response->streamedContent());

        Storage::disk('s3')->assertExists("composer-dists/acme/widgets/{$reference}.zip");
        $this->assertSame('zip-bytes', Storage::disk('s3')->get("composer-dists/acme/widgets/{$reference}.zip"));
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer ghp_secret'));

        // A second download is served from the cache without touching GitHub.
        $this->get("/dist/acme/widgets/{$reference}.zip")->assertOk();
        Http::assertSentCount(1);
    }

    public function test_a_failed_zipball_download_is_not_cached(): void
    {
        config(['filesystems.dists' => 's3']);
        Storage::fake('s3');
        Http::fake([
            'api.github.com/repos/acme/widgets/zipball/*' => Http::response('nope', 500),
        ]);

        $this->makeServedPackage();
        $reference = str_repeat('b', 40);

        $this->get("/dist/acme/widgets/{$reference}.zip")->assertServerError();

        Storage::disk('s3')->assertMissing("composer-dists/acme/widgets/{$reference}.zip");
    }

    public function test_a_failed_dist_write_is_removed_from_the_disk(): void
    {
        config(['filesystems.dists' => 's3']);
        Http::fake([
            'api.github.com/repos/acme/widgets/zipball/*' => Http::response('zip-bytes'),
        ]);

        $reference = str_repeat('b', 40);
        $path = "composer-dists/acme/widgets/{$reference}.zip";

        // A write that fails after partially uploading must not leave the
        // truncated object behind for the next request to serve.
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->with($path)->andReturnFalse();
        $disk->shouldReceive('writeStream')->once()->with($path, Mockery::any())->andReturnFalse();
        $disk->shouldReceive('delete')->once()->with($path);

        Storage::set('s3', $disk);

        $this->makeServedPackage();

        $this->get("/dist/acme/widgets/{$reference}.zip")->assertServerError();
    }

    public function test_the_dist_endpoint_rejects_unknown_references(): void
    {
        $this->makeServedPackage();

        $this->get('/dist/acme/widgets/'.str_repeat('9', 40).'.zip')->assertNotFound();
        $this->get('/dist/acme/missing/'.str_repeat('b', 40).'.zip')->assertNotFound();
    }
}
