<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Services\PackageSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\Zipball;
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
            'api.github.com/repos/acme/widgets/zipball/*' => fn () => Http::response(Zipball::bytes(), 200, [
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
            $this->assertSame(sha1((string) $disk->get($version->archive_path)), $version->shasum);
            $disk->assertExists($version->archive_path);

            // Paths carry the composer name, not the pre-sync placeholder.
            $this->assertStringStartsWith('packages/acme/widgets/', $version->archive_path);
        }
    }

    /**
     * The (repository_id, name) unique index would reject the first-sync
     * rename with a bare query error; the conflict must fail the sync with a
     * reason a human can act on instead.
     */
    public function test_a_composer_name_already_published_in_the_repository_fails_the_sync_clearly(): void
    {
        $this->fakeGitHub();

        // Another package in the same (default) Composer repository already
        // publishes the name this repository's composer.json declares.
        Package::factory()->create([
            'name' => 'acme/widgets',
            'repository' => 'https://github.com/acme/widgets-fork',
        ]);

        $package = $this->makePackage();

        try {
            app(PackageSynchronizer::class)->sync($package);
            $this->fail('The name conflict should have failed the sync.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('acme/widgets', $exception->getMessage());
        }

        $package->refresh();

        // The reason lands where the panel reads it, and the placeholder name
        // survives — the row was never renamed into the collision.
        $this->assertStringContainsString('already publishes', (string) $package->sync_error);
        $this->assertSame('acme/widgets-placeholder', $package->name);
    }

    /**
     * The name is read out of a file the repository's owner writes, and
     * nothing puts it through Composer on the way here — so "Composer
     * validated it already" was never true. Adopted unchecked it went straight
     * into the archive path, and a name beginning `../` put the zip outside
     * the prefix archives:clean sweeps and inside the one mirror:prune does.
     */
    public function test_a_declared_name_that_is_not_a_composer_name_fails_the_sync(): void
    {
        $this->fakeGitHub([
            'api.github.com/repos/acme/widgets/contents/composer.json*' => Http::response([
                'name' => '../mirror/9/evil/pkg',
                'type' => 'library',
            ]),
        ]);

        $package = $this->makePackage();

        try {
            app(PackageSynchronizer::class)->sync($package);
            $this->fail('A name outside the Composer grammar should have failed the sync.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not a Composer package name', $exception->getMessage());
        }

        $package->refresh();

        $this->assertStringContainsString('not a Composer package name', (string) $package->sync_error);
        $this->assertSame('acme/widgets-placeholder', $package->name);

        // Nothing was written anywhere on the disk, least of all beside the
        // mirror cache.
        $this->assertSame([], Storage::disk(config('filesystems.dists'))->allFiles());
    }

    /**
     * A first sync whose default branch carries no composer.json still learns
     * the package's real name — from the refs, at finalize. This is the one
     * case where the name arrives after the imports rather than before them,
     * and the guard below must not close it.
     */
    public function test_a_first_sync_takes_its_name_from_a_ref_when_the_default_branch_has_none(): void
    {
        $this->fakeGitHub([
            'api.github.com/repos/acme/widgets/contents/composer.json*' => fn ($request) => str_contains($request->url(), 'ref=')
                ? Http::response(['name' => 'acme/widgets', 'type' => 'library'])
                : Http::response([], 404),
        ]);

        $package = $this->makePackage();

        app(PackageSynchronizer::class)->sync($package);

        $package->refresh();

        $this->assertSame('acme/widgets', $package->name);
        $this->assertNull($package->sync_error);
    }

    /**
     * A composer.json that starts declaring another name is not a metadata
     * update: applied silently it would move the package's identity — and
     * every future dist URL — onto whatever the newest ref claims.
     */
    public function test_a_composer_name_changed_upstream_is_reported_rather_than_applied(): void
    {
        $name = 'acme/widgets';

        $this->fakeGitHub([
            'api.github.com/repos/acme/widgets/contents/composer.json*' => function () use (&$name) {
                return Http::response(['name' => $name, 'description' => 'Widgets for Acme.', 'type' => 'library']);
            },
        ]);

        $package = $this->makePackage();

        app(PackageSynchronizer::class)->sync($package);

        $this->assertSame('acme/widgets', $package->refresh()->name);

        // A tag pointing at a fork looks exactly like a deliberate rename.
        $name = 'evil/widgets';

        app(PackageSynchronizer::class)->sync($package, force: true);

        $package->refresh();

        $this->assertSame('acme/widgets', $package->name);
        $this->assertStringContainsString('evil/widgets', (string) $package->sync_error);
        $this->assertStringContainsString('left alone', (string) $package->sync_error);

        // Reported, not failed: the versions themselves synced fine.
        $this->assertNotNull($package->last_synced_at);
        $this->assertSame('1.1.0', $package->latest_version);
    }

    /**
     * The same refusal is what keeps a rename off the (repository_id, name)
     * unique index, which finalize() used to walk straight into — storing the
     * resulting SQL error as the package's sync_error.
     */
    public function test_a_rename_onto_a_name_the_repository_already_publishes_never_reaches_the_index(): void
    {
        $name = 'acme/widgets';

        $this->fakeGitHub([
            'api.github.com/repos/acme/widgets/contents/composer.json*' => function () use (&$name) {
                return Http::response(['name' => $name, 'type' => 'library']);
            },
        ]);

        Package::factory()->create([
            'name' => 'acme/rival',
            'repository' => 'https://github.com/acme/rival',
        ]);

        $package = $this->makePackage();

        app(PackageSynchronizer::class)->sync($package);

        $name = 'acme/rival';

        app(PackageSynchronizer::class)->sync($package, force: true);

        $package->refresh();

        $this->assertSame('acme/widgets', $package->name);
        $this->assertStringContainsString('acme/rival', (string) $package->sync_error);
        $this->assertStringNotContainsString('SQLSTATE', (string) $package->sync_error);
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

        $disk = Storage::disk(config('filesystems.dists'));

        $this->assertNotNull($version->archive_path);

        $this->assertSame(sha1((string) $disk->get($version->archive_path)), $version->shasum);
        $disk->assertExists($version->archive_path);
    }

    /**
     * A row can outlive its file — object storage loss, or a deploy that wiped
     * archives while the database survived. The sync no longer asks the disk
     * about every stored version to find that out (one HEAD per version per
     * sync, hourly, to learn nothing); archives:audit answers it for the whole
     * registry at once and clears the columns, which is what puts the version
     * back on the ordinary re-import path.
     */
    public function test_an_unchanged_version_whose_archive_file_is_gone_is_rebuilt_after_the_audit(): void
    {
        $this->fakeGitHub();

        $package = $this->makePackage();

        app(PackageSynchronizer::class)->sync($package);

        $version = $package->versions()->where('version', '1.1.0')->sole();
        Storage::disk(config('filesystems.dists'))->delete($version->archive_path);

        // A sync on its own no longer notices, and must not: that is the whole
        // point of not asking the disk per version.
        app(PackageSynchronizer::class)->sync($package);

        $this->assertSame($version->archive_path, $version->fresh()->archive_path);

        // The audit only judges rows settled past its grace window.
        $this->travelTo(now()->addHours(2));

        $this->artisan('archives:audit')->assertSuccessful();

        $this->assertNull($version->fresh()->archive_path);

        app(PackageSynchronizer::class)->sync($package);

        $version->refresh();

        $disk = Storage::disk(config('filesystems.dists'));

        $this->assertNotNull($version->archive_path);

        $this->assertSame(sha1((string) $disk->get($version->archive_path)), $version->shasum);
        $disk->assertExists($version->archive_path);
    }

    /**
     * A provider with no date for a commit has answered; the row is complete
     * without one. Treated as unfinished it was re-imported — composer.json,
     * commit and full zipball — on every sync for the rest of its life.
     */
    public function test_a_version_the_provider_has_no_date_for_is_not_reimported_forever(): void
    {
        $this->fakeGitHub([
            'api.github.com/repos/acme/widgets/commits/*' => Http::response([], 404),
        ]);

        $package = $this->makePackage();

        app(PackageSynchronizer::class)->sync($package);

        $requestsAfterFirstSync = count(Http::recorded());

        app(PackageSynchronizer::class)->sync($package);

        // Two ref listings and nothing else, exactly as for a dated version.
        $this->assertSame($requestsAfterFirstSync + 2, count(Http::recorded()));
        $this->assertSame(4, $package->versions()->count());
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
                : Http::response(Zipball::bytes(), 200, ['Content-Type' => 'application/zip']),
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
