<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Services\PackageSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class PackageSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Syncing stores an archive per version on the dist disk.
        Storage::fake(config('filesystems.dists'));
    }

    /**
     * Fake the endpoints a sync reads. Overrides are registered ahead of the
     * defaults because the first matching stub wins.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function fakeGitHub(array $overrides = []): void
    {
        $composerJson = [
            'name' => 'acme/widgets',
            'description' => 'Widgets for Acme.',
            'type' => 'library',
            'require' => ['php' => '^8.3'],
        ];

        Http::fake($overrides + [
            'api.github.com/repos/acme/widgets/tags*' => Http::response([
                ['name' => 'v1.0.0', 'commit' => ['sha' => str_repeat('a', 40)]],
                ['name' => 'v1.1.0', 'commit' => ['sha' => str_repeat('b', 40)]],
                ['name' => 'not-a-version', 'commit' => ['sha' => str_repeat('c', 40)]],
            ]),
            'api.github.com/repos/acme/widgets/branches*' => Http::response([
                ['name' => 'main', 'commit' => ['sha' => str_repeat('d', 40)]],
                ['name' => '2.x', 'commit' => ['sha' => str_repeat('e', 40)]],
            ]),
            'api.github.com/repos/acme/widgets/contents/composer.json*' => Http::response($composerJson),
            'api.github.com/repos/acme/widgets/commits/*' => Http::response([
                'commit' => ['committer' => ['date' => '2026-02-01T12:00:00Z']],
            ]),
            'api.github.com/repos/acme/widgets/zipball/*' => fn () => Http::response('zip-bytes', 200, [
                'Content-Type' => 'application/zip',
            ]),
        ]);
    }

    private function makePackage(): Package
    {
        return Package::factory()->unreleased()->create([
            'name' => 'acme/widgets-placeholder',
            'repository' => 'https://github.com/acme/widgets',
            'token' => 'ghp_secret',
        ]);
    }

    public function test_sync_stores_tag_and_branch_versions(): void
    {
        $this->fakeGitHub();

        $package = $this->makePackage();

        app(PackageSynchronizer::class)->sync($package);

        $package->refresh();

        $this->assertSame('acme/widgets', $package->name);
        // Tags are stored under their normalized spelling: v1.1.0 → 1.1.0.
        $this->assertSame('1.1.0', $package->latest_version);
        $this->assertSame('Widgets for Acme.', $package->description);
        $this->assertSame('library', $package->type);
        $this->assertNotNull($package->last_synced_at);
        $this->assertNull($package->sync_error);

        $versions = $package->versions()->pluck('is_dev', 'version');

        $this->assertSame([
            '1.0.0' => false,
            '1.1.0' => false,
            '2.x-dev' => true,
            'dev-main' => true,
        ], $versions->sortKeys()->all());

        // The malformed tag is ignored rather than served.
        $this->assertNull($package->versions()->where('version', 'not-a-version')->first());
    }

    public function test_sync_stores_an_archive_with_a_shasum_for_every_version(): void
    {
        $this->fakeGitHub();

        $package = $this->makePackage();

        app(PackageSynchronizer::class)->sync($package);

        $disk = Storage::disk(config('filesystems.dists'));

        foreach ($package->versions()->get() as $version) {
            $this->assertNotNull($version->archive_path, "{$version->version} has no stored archive.");
            $this->assertSame(sha1('zip-bytes'), $version->shasum);
            $disk->assertExists($version->archive_path);

            // Paths carry the composer name, not the pre-sync placeholder.
            $this->assertStringStartsWith('packages/acme/widgets/', $version->archive_path);
        }
    }

    public function test_an_unchanged_version_missing_its_archive_is_backfilled(): void
    {
        $this->fakeGitHub();

        $package = $this->makePackage();

        app(PackageSynchronizer::class)->sync($package);

        // A row from before archives were stored: same ref, no archive.
        $version = $package->versions()->where('version', '1.1.0')->sole();
        $version->forceFill(['archive_path' => null, 'shasum' => null])->save();

        app(PackageSynchronizer::class)->sync($package);

        $version->refresh();

        $this->assertNotNull($version->archive_path);
        $this->assertSame(sha1('zip-bytes'), $version->shasum);
        Storage::disk(config('filesystems.dists'))->assertExists($version->archive_path);
    }

    public function test_an_unchanged_version_whose_archive_file_is_gone_is_rebuilt(): void
    {
        $this->fakeGitHub();

        $package = $this->makePackage();

        app(PackageSynchronizer::class)->sync($package);

        // The columns say stored, but the disk lost the file — object storage
        // loss, or a deploy that wiped archives while the database survived.
        $version = $package->versions()->where('version', '1.1.0')->sole();
        Storage::disk(config('filesystems.dists'))->delete($version->archive_path);

        app(PackageSynchronizer::class)->sync($package);

        $version->refresh();

        $this->assertNotNull($version->archive_path);
        Storage::disk(config('filesystems.dists'))->assertExists($version->archive_path);
    }

    public function test_a_zipball_that_is_not_a_zip_fails_the_sync(): void
    {
        // A proxy's HTML error page arriving with a 200 must not be stored
        // and served to Composer as an archive.
        $this->fakeGitHub([
            'api.github.com/repos/acme/widgets/zipball/*' => Http::response('<html>maintenance</html>', 200, [
                'Content-Type' => 'text/html',
            ]),
        ]);

        $package = $this->makePackage();

        try {
            app(PackageSynchronizer::class)->sync($package);
            $this->fail('Expected the sync to reject the non-zip download.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('instead of a zip archive', $exception->getMessage());
        }

        $this->assertStringContainsString('instead of a zip archive', (string) $package->refresh()->sync_error);
        $this->assertSame(0, $package->versions()->count());
    }

    /**
     * One broken ref costs one version: the others still land, and the gap is
     * recorded on the package instead of failing the sync outright.
     */
    public function test_one_bad_ref_does_not_fail_the_rest_of_the_sync(): void
    {
        // Only v1.1.0's zipball comes back as an HTML error page.
        $this->fakeGitHub([
            'api.github.com/repos/acme/widgets/zipball/*' => fn ($request) => str_contains($request->url(), str_repeat('b', 40))
                ? Http::response('<html>maintenance</html>', 200, ['Content-Type' => 'text/html'])
                : Http::response('zip-bytes', 200, ['Content-Type' => 'application/zip']),
        ]);

        $package = $this->makePackage();

        app(PackageSynchronizer::class)->sync($package);

        $package->refresh();

        $this->assertSame(
            ['1.0.0', '2.x-dev', 'dev-main'],
            $package->versions()->pluck('version')->sort()->values()->all(),
        );

        $this->assertNotNull($package->last_synced_at);
        $this->assertSame('1.0.0', $package->latest_version);
        $this->assertSame('1 of 4 version imports failed; the next sync will retry them.', $package->sync_error);
    }

    public function test_sync_records_the_commit_date_of_each_version(): void
    {
        $this->fakeGitHub();

        $package = $this->makePackage();

        app(PackageSynchronizer::class)->sync($package);

        $released = $package->versions()->where('version', '1.1.0')->sole()->released_at;

        $this->assertNotNull($released);
        $this->assertSame('2026-02-01 12:00:00', $released->utc()->toDateTimeString());

        // Every version carries a date, dev branches included.
        $this->assertSame(0, $package->versions()->whereNull('released_at')->count());
    }

    public function test_a_version_without_a_commit_date_is_stored_anyway(): void
    {
        // GitHub answering without a date must not cost the version its row.
        $this->fakeGitHub([
            'api.github.com/repos/acme/widgets/commits/*' => Http::response([], 404),
        ]);

        $package = $this->makePackage();

        app(PackageSynchronizer::class)->sync($package);

        $this->assertSame(4, $package->versions()->count());
        $this->assertNull($package->versions()->where('version', '1.1.0')->sole()->released_at);
    }

    public function test_a_second_sync_does_not_refetch_refs_that_have_not_moved(): void
    {
        $this->fakeGitHub();

        $package = $this->makePackage();

        app(PackageSynchronizer::class)->sync($package);

        $requestsAfterFirstSync = count(Http::recorded());

        app(PackageSynchronizer::class)->sync($package);

        // The second sync still lists tags and branches — it cannot know what
        // moved otherwise — but reads no composer.json, commit, or zipball
        // for the four unchanged refs.
        $refListings = 2;

        $this->assertSame($requestsAfterFirstSync + $refListings, count(Http::recorded()));
        $this->assertSame(4, $package->versions()->count());
    }

    public function test_a_moved_branch_is_refetched_on_the_next_sync(): void
    {
        $head = str_repeat('d', 40);
        $moved = str_repeat('1', 40);

        $this->fakeGitHub([
            // A closure over the current head, so the second sync sees the
            // branch pointing somewhere new.
            'api.github.com/repos/acme/widgets/branches*' => function () use (&$head) {
                return Http::response([['name' => 'main', 'commit' => ['sha' => $head]]]);
            },
            'api.github.com/repos/acme/widgets/commits/*' => function ($request) use ($moved) {
                return Http::response(['commit' => ['committer' => [
                    'date' => str_contains($request->url(), $moved)
                        ? '2026-03-09T09:30:00Z'
                        : '2026-02-01T12:00:00Z',
                ]]]);
            },
        ]);

        $package = $this->makePackage();

        app(PackageSynchronizer::class)->sync($package);

        // The branch head advances, so its version has to be read again even
        // though the ref name is unchanged.
        $head = $moved;

        app(PackageSynchronizer::class)->sync($package);

        $main = $package->versions()->where('version', 'dev-main')->sole();

        $this->assertSame($moved, $main->reference);
        $this->assertSame('2026-03-09 09:30:00', $main->released_at->utc()->toDateTimeString());
    }

    public function test_sync_removes_versions_that_no_longer_exist(): void
    {
        $this->fakeGitHub();

        $package = $this->makePackage();
        $package->versions()->create([
            'version' => 'v0.9.0',
            'reference' => str_repeat('f', 40),
            'is_dev' => false,
            'metadata' => ['name' => 'acme/widgets', 'version' => 'v0.9.0'],
        ]);

        app(PackageSynchronizer::class)->sync($package);

        $this->assertNull($package->versions()->where('version', 'v0.9.0')->first());
    }

    public function test_sync_authenticates_with_the_package_token(): void
    {
        $this->fakeGitHub();

        app(PackageSynchronizer::class)->sync($this->makePackage());

        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer ghp_secret'));
    }

    public function test_a_failed_sync_records_the_error_on_the_package(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

        $package = $this->makePackage();

        try {
            app(PackageSynchronizer::class)->sync($package);
            $this->fail('Expected the sync to throw.');
        } catch (RequestException) {
            // Expected.
        }

        $this->assertNotNull($package->refresh()->sync_error);
    }

    public function test_the_sync_command_syncs_a_package_by_name(): void
    {
        $this->fakeGitHub();

        $package = $this->makePackage();

        $this->artisan('packages:sync', ['name' => $package->name])
            ->assertSuccessful();

        $this->assertSame(4, $package->versions()->count());
    }

    public function test_the_sync_command_finds_an_unsynced_package_by_its_composer_name(): void
    {
        $this->fakeGitHub();

        // Before the first sync the stored name is still a placeholder, so the
        // composer name only matches via the repository.
        $package = $this->makePackage();

        $this->artisan('packages:sync', ['name' => 'acme/widgets'])
            ->assertSuccessful();

        $this->assertSame('acme/widgets', $package->refresh()->name);
        $this->assertSame(4, $package->versions()->count());
    }

    public function test_the_sync_command_does_not_sync_unrelated_packages(): void
    {
        $this->fakeGitHub();

        $this->makePackage();
        $other = Package::factory()->create([
            'name' => 'acme/other',
            'repository' => 'https://github.com/acme/other',
        ]);

        $this->artisan('packages:sync', ['name' => 'acme/widgets'])
            ->assertSuccessful();

        $this->assertSame(0, $other->versions()->count());
    }

    public function test_the_sync_command_reports_a_name_that_matches_nothing(): void
    {
        $this->fakeGitHub();

        $this->makePackage();

        $this->artisan('packages:sync', ['name' => 'nobody/nothing'])
            ->expectsOutputToContain('No matching packages found.')
            ->assertFailed();
    }

    public function test_sync_reads_every_page_of_tags(): void
    {
        // Two full pages plus a partial third: the old ten-page ceiling is not
        // the thing being tested, only that pagination runs to exhaustion.
        $pages = [
            $this->tagPage(1, 100),
            $this->tagPage(101, 100),
            $this->tagPage(201, 5),
        ];

        $page = 0;

        $this->fakeGitHub([
            'api.github.com/repos/acme/widgets/tags*' => function () use ($pages, &$page) {
                return Http::response($pages[$page++] ?? []);
            },
            'api.github.com/repos/acme/widgets/branches*' => Http::response([]),
        ]);

        $package = $this->makePackage();

        app(PackageSynchronizer::class)->sync($package);

        $this->assertSame(205, $package->versions()->count());
        $this->assertNotNull($package->versions()->where('version', '1.0.205')->first());
    }

    public function test_sync_stops_at_a_full_page_that_advertises_no_successor(): void
    {
        // A full page is normally the signal to keep going; the Link header
        // overrides that so a repository with exactly 100 tags isn't re-fetched.
        $this->fakeGitHub([
            'api.github.com/repos/acme/widgets/tags*' => Http::response(
                $this->tagPage(1, 100),
                headers: ['Link' => '<https://api.github.com/repositories/1/tags?page=1>; rel="prev"'],
            ),
            'api.github.com/repos/acme/widgets/branches*' => Http::response([]),
        ]);

        $package = $this->makePackage();

        app(PackageSynchronizer::class)->sync($package);

        $this->assertSame(100, $package->versions()->count());

        $tagRequests = collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/tags'))
            ->count();

        $this->assertSame(1, $tagRequests);
    }

    public function test_sync_fails_loudly_rather_than_truncating_an_endless_repository(): void
    {
        // Every page comes back full and advertises a next page, so pagination
        // would never terminate on its own.
        $this->fakeGitHub([
            'api.github.com/repos/acme/widgets/tags*' => Http::response(
                $this->tagPage(1, 100),
                headers: ['Link' => '<https://api.github.com/repositories/1/tags?page=99999>; rel="next"'],
            ),
            'api.github.com/repos/acme/widgets/branches*' => Http::response([]),
        ]);

        $package = $this->makePackage();

        try {
            app(PackageSynchronizer::class)->sync($package);
            $this->fail('Expected the sync to throw rather than silently truncate.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('refusing to sync from a partial list', $exception->getMessage());
        }

        // The failure is recorded on the package instead of passing as a
        // successful but incomplete sync.
        $this->assertStringContainsString('partial list', (string) $package->refresh()->sync_error);
        $this->assertNull($package->last_synced_at);
    }

    /**
     * A page of sequential version tags starting at the given number.
     *
     * @return list<array<string, mixed>>
     */
    private function tagPage(int $start, int $count): array
    {
        return array_map(fn (int $i): array => [
            'name' => "v1.0.{$i}",
            'commit' => ['sha' => str_pad((string) $i, 40, '0', STR_PAD_LEFT)],
        ], range($start, $start + $count - 1));
    }
}
