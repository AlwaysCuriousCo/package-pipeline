<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Repository;
use Composer\MetadataMinifier\MetadataMinifier;
use DateTimeInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter as FlysystemLocalAdapter;
use Tests\TestCase;

class ComposerRepositoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Paths the dist disk was asked to sign a URL for.
     *
     * @var list<string>
     */
    private array $signed = [];

    /**
     * A dist disk that hands out URLs of its own, which is what `DIST_DISK=s3`
     * gets in production.
     *
     * Storage::fake() cannot stand in for one: it builds a local disk whatever
     * it is named, and a local disk is precisely the case the endpoint streams
     * rather than redirects. So the disk is assembled here — real files
     * underneath, a signer on top — and every minted URL recorded, because
     * "none was minted" is half of what these tests assert.
     */
    private function fakeSigningDisk(): FilesystemAdapter
    {
        $root = storage_path('framework/testing/disks/signing');

        File::cleanDirectory($root);

        $adapter = new FlysystemLocalAdapter($root);

        $disk = new FilesystemAdapter(new Flysystem($adapter), $adapter);

        // By reference because Laravel rebinds the callback to the disk it
        // belongs to, which leaves the test's own $this out of reach.
        $signed = &$this->signed;

        $disk->buildTemporaryUrlsUsing(function (string $path, DateTimeInterface $expiration) use (&$signed): string {
            $signed[] = $path;

            return "https://objects.test/{$path}?expires={$expiration->getTimestamp()}";
        });

        Storage::set('s3', $disk);
        config(['filesystems.dists' => 's3']);

        return $disk;
    }

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
            'order' => '1.1.0.0',
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
            // Patterns bound what Composer will ask about without inlining
            // every name, which would defeat lazy loading and hand the whole
            // package list to anyone who fetches the root.
            ->assertJsonMissingPath('available-packages')
            ->assertJsonPath('available-package-patterns', ['acme/*']);
    }

    public function test_the_root_advertises_one_pattern_per_served_vendor(): void
    {
        $this->makeServedPackage();
        $this->makeServedPackageNamed('acme/gadgets');
        $this->makeServedPackageNamed('other/widgets');

        // A package with no versions resolves to nothing, so naming its vendor
        // would only send Composer somewhere that answers 404.
        Package::factory()->create(['name' => 'unsynced/thing']);

        $this->get('/packages.json')
            ->assertOk()
            ->assertJsonPath('available-package-patterns', ['acme/*', 'other/*']);
    }

    public function test_an_abandoned_package_says_so_in_its_metadata(): void
    {
        $this->makeServedPackage()->update(['abandoned' => true]);

        $this->get('/p2/acme/widgets.json')
            ->assertOk()
            ->assertJsonPath('packages.acme/widgets.0.abandoned', true);
    }

    public function test_an_abandonment_can_name_its_replacement(): void
    {
        $this->makeServedPackage()->update([
            'abandoned' => true,
            'replacement_package' => 'acme/gadgets',
        ]);

        $this->get('/p2/acme/widgets.json')
            ->assertOk()
            ->assertJsonPath('packages.acme/widgets.0.abandoned', 'acme/gadgets');
    }

    public function test_abandoning_a_package_supersedes_its_cached_metadata(): void
    {
        $package = $this->makeServedPackage();

        // Warm the payload cache, whose key is cut from the same fingerprint
        // the ETag is. An abandonment that fingerprint failed to notice would
        // be served from this entry for as long as it lives.
        $this->get('/p2/acme/widgets.json')
            ->assertOk()
            ->assertJsonMissingPath('packages.acme/widgets.0.abandoned');

        // Deliberately no time travel: the two writes land in the same second,
        // which is exactly the case a timestamp cannot tell apart.
        $package->update(['abandoned' => true, 'replacement_package' => 'acme/gadgets']);

        $this->get('/p2/acme/widgets.json')
            ->assertOk()
            ->assertJsonPath('packages.acme/widgets.0.abandoned', 'acme/gadgets');
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

    public function test_metadata_is_served_in_the_minified_format(): void
    {
        $this->makeServedPackage();

        $this->get('/p2/acme/widgets.json')
            ->assertOk()
            // Composer reads an absent key as "already expanded", so declaring
            // the format is the whole of the opt-in.
            ->assertJsonPath('minified', 'composer/2.0');
    }

    public function test_minification_omits_what_a_version_shares_with_the_one_before_it(): void
    {
        $package = $this->makeServedPackage();

        // Identical to v1.1.0 in everything but the version itself, which is
        // the ordinary case: a release line's requirements rarely move.
        $package->versions()->create([
            'version' => 'v1.0.0',
            'order' => '1.0.0.0',
            'reference' => str_repeat('c', 40),
            'is_dev' => false,
            'released_at' => '2026-01-01 12:00:00',
            'metadata' => [
                'name' => 'acme/widgets',
                'version' => 'v1.0.0',
                'type' => 'library',
                'require' => ['php' => '^8.3'],
            ],
        ]);

        $versions = $this->get('/p2/acme/widgets.json')->assertOk()->json('packages.acme/widgets');

        // Newest first, and the first entry is the complete one.
        $this->assertSame('v1.1.0', $versions[0]['version']);
        $this->assertArrayHasKey('type', $versions[0]);

        // The second carries only what actually changed.
        $this->assertSame(
            ['version', 'time', 'dist'],
            array_keys($versions[1]),
        );
    }

    public function test_expanding_the_minified_response_recovers_every_version_whole(): void
    {
        // The strongest statement available: what a Composer client ends up
        // with after expansion, spelled out rather than derived from the code
        // that produced it.
        $package = $this->makeServedPackage();

        $package->versions()->create([
            'version' => 'v1.0.0',
            'order' => '1.0.0.0',
            'reference' => str_repeat('c', 40),
            'is_dev' => false,
            'released_at' => '2026-01-01 12:00:00',
            'archive_path' => 'packages/acme/widgets/v100.zip',
            'shasum' => sha1('older-zip-bytes'),
            'metadata' => [
                'name' => 'acme/widgets',
                'version' => 'v1.0.0',
                'type' => 'library',
                // A key v1.1.0 does not carry, and — by its absence here —
                // a `require` that v1.1.0 does. Expansion has to honour the
                // format's "__unset" as well as its carrying-forward.
                'description' => 'The first cut.',
            ],
        ]);

        $expanded = MetadataMinifier::expand(
            $this->get('/p2/acme/widgets.json')->assertOk()->json('packages.acme/widgets'),
        );

        $this->assertEquals([
            [
                'name' => 'acme/widgets',
                'version' => 'v1.1.0',
                'type' => 'library',
                'require' => ['php' => '^8.3'],
                'time' => '2026-02-01T12:00:00+00:00',
                'dist' => [
                    'type' => 'zip',
                    'url' => url('/dist/acme/widgets/'.str_repeat('b', 40).'.zip'),
                    'reference' => str_repeat('b', 40),
                    'shasum' => sha1('zip-bytes'),
                ],
            ],
            [
                'name' => 'acme/widgets',
                'version' => 'v1.0.0',
                'type' => 'library',
                'description' => 'The first cut.',
                'time' => '2026-01-01T12:00:00+00:00',
                'dist' => [
                    'type' => 'zip',
                    'url' => url('/dist/acme/widgets/'.str_repeat('c', 40).'.zip'),
                    'reference' => str_repeat('c', 40),
                    'shasum' => sha1('older-zip-bytes'),
                ],
            ],
        ], $expanded);
    }

    public function test_the_dist_endpoint_serves_the_stored_archive(): void
    {
        // Storage::fake() builds a local disk under any name, so this is the
        // streaming path: the bytes come out through PHP, as they do for a
        // single-server install. The redirect a disk with its own URLs gets
        // has its own coverage below.
        config(['filesystems.dists' => 's3']);
        Storage::fake('s3');
        Storage::disk('s3')->put('packages/acme/widgets/v110.zip', 'zip-bytes');
        Http::fake();

        $this->makeServedPackage();
        $reference = str_repeat('b', 40);

        $response = $this->get("/dist/acme/widgets/{$reference}.zip")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/zip')
            // The URL is keyed by commit, so the bytes behind it never change
            // and a client may keep them for as long as it likes. Private:
            // a shared cache is no party to who this app serves an archive to.
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, private');

        $this->assertSame('zip-bytes', $response->streamedContent());

        // Serving is storage only. GitHub is never consulted, so consumers
        // need no GitHub credentials and an outage there changes nothing.
        Http::assertNothingSent();
    }

    public function test_a_dist_disk_with_its_own_urls_is_redirected_to(): void
    {
        // Streaming the zip from PHP holds a worker for the whole transfer,
        // and a `composer install` fetches one archive per package. A disk
        // that can sign its own URLs is handed the transfer instead.
        $this->fakeSigningDisk()->put('packages/acme/widgets/v110.zip', 'zip-bytes');

        $package = $this->makeServedPackage();
        $reference = str_repeat('b', 40);

        $location = (string) $this->get("/dist/acme/widgets/{$reference}.zip")
            ->assertStatus(302)
            ->assertRedirectContains('https://objects.test/packages/acme/widgets/v110.zip')
            // The archive may be immutable, but a URL that expires in minutes
            // is not: a cache replaying this redirect would fail the install.
            ->assertHeader('Cache-Control', 'no-store, private')
            ->headers->get('Location');

        // The signature is the only authorisation the storage service applies,
        // so the grant it hands out is short-lived by construction.
        $expires = (int) Str::after($location, 'expires=');

        $this->assertGreaterThan(now()->timestamp, $expires);
        $this->assertLessThanOrEqual(now()->addMinutes(15)->timestamp, $expires);

        // Redirecting is still serving: the archive left the registry, and the
        // request that sent it there is the only one that will count it.
        $this->assertSame(1, $package->fresh()->total_downloads);
        $this->assertSame(1, $package->versions()->where('reference', $reference)->sole()->total_downloads);
    }

    public function test_a_local_dist_disk_streams_rather_than_redirecting(): void
    {
        // A local disk can sign URLs too — `serve` mounts a route that answers
        // them — but that route is this same application, so redirecting to it
        // would buy a round trip and hand the bytes back to PHP anyway.
        config(['filesystems.dists' => 'local']);
        Storage::fake('local');
        Storage::disk('local')->put('packages/acme/widgets/v110.zip', 'zip-bytes');

        $this->makeServedPackage();

        $response = $this->get('/dist/acme/widgets/'.str_repeat('b', 40).'.zip')->assertOk();

        $this->assertSame('zip-bytes', $response->streamedContent());
    }

    public function test_no_url_is_minted_for_a_client_that_may_not_have_the_archive(): void
    {
        $this->fakeSigningDisk()->put('packages/acme/widgets/v110.zip', 'zip-bytes');

        $package = $this->makeServedPackage();
        $package->composerRepository()->associate(
            Repository::factory()->create(['path' => 'internal', 'public' => false]),
        )->save();

        $reference = str_repeat('b', 40);

        // Unauthenticated against the repository that serves it...
        $this->getJson("/r/internal/dist/acme/widgets/{$reference}.zip")->assertUnauthorized();

        // ...and asking a repository that does not serve it at all.
        $this->get("/dist/acme/widgets/{$reference}.zip")->assertNotFound();

        // A signed URL is a grant this app cannot take back for as long as it
        // lives, so none exists until the caller has been cleared for exactly
        // the archive it names.
        $this->assertCount(0, $this->signed);
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
