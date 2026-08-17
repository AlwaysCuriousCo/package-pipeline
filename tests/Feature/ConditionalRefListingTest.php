<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Services\PackageSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Zipball;
use Tests\TestCase;

/**
 * Ref listings asked conditionally.
 *
 * Every sync lists every tag and every branch — it is the only way to learn
 * what moved — and for a webhook-covered package that is once per push plus
 * hourly from the schedule, almost always to be told the same refs again. An
 * `If-None-Match` turns that into a 304 with no body, which GitHub does not
 * count against the primary rate limit at all.
 *
 * The load-bearing case here is the second one: a 304 carries no refs, and a
 * client that read it as "this repository has no tags" would prune every
 * version the package serves.
 */
class ConditionalRefListingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.dists'));

        // A request this suite forgot to fake must fail, not reach GitHub.
        Http::preventStrayRequests();
    }

    /**
     * GitHub as it actually answers: listings carry an ETag, and revalidate to
     * 304 when the caller offers it back.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function fakeGitHub(array $overrides = []): void
    {
        Http::fake($overrides + [
            'api.github.com/repos/acme/widgets/tags*' => fn ($request) => $request->hasHeader('If-None-Match', '"tags-1"')
                ? Http::response('', 304)
                : Http::response([
                    ['name' => 'v1.0.0', 'commit' => ['sha' => str_repeat('a', 40)]],
                ], 200, ['ETag' => '"tags-1"']),
            'api.github.com/repos/acme/widgets/branches*' => fn ($request) => $request->hasHeader('If-None-Match', '"branches-1"')
                ? Http::response('', 304)
                : Http::response([
                    ['name' => 'main', 'commit' => ['sha' => str_repeat('d', 40)]],
                ], 200, ['ETag' => '"branches-1"']),
            'api.github.com/repos/acme/widgets/contents/composer.json*' => Http::response([
                'name' => 'acme/widgets',
                'type' => 'library',
            ]),
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

    public function test_the_second_sync_offers_back_the_etag_the_first_was_given(): void
    {
        $this->fakeGitHub();

        $package = $this->makePackage();

        app(PackageSynchronizer::class)->sync($package);

        // Nothing to revalidate against on a first listing.
        Http::assertSent(fn ($request): bool => ! str_contains($request->url(), '/tags')
            || ! $request->hasHeader('If-None-Match'));

        app(PackageSynchronizer::class)->sync($package);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/tags')
            && $request->hasHeader('If-None-Match', '"tags-1"'));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/branches')
            && $request->hasHeader('If-None-Match', '"branches-1"'));
    }

    /**
     * A 304 has no body. Read as an empty listing it would say the repository
     * publishes nothing, and prune() would delete every stored version.
     */
    public function test_a_not_modified_listing_keeps_the_versions_it_described(): void
    {
        $this->fakeGitHub();

        $package = $this->makePackage();

        app(PackageSynchronizer::class)->sync($package);

        $this->assertSame(['1.0.0', 'dev-main'], $package->versions()->pluck('version')->sort()->values()->all());

        app(PackageSynchronizer::class)->sync($package);

        $package->refresh();

        $this->assertSame(['1.0.0', 'dev-main'], $package->versions()->pluck('version')->sort()->values()->all());
        $this->assertNull($package->sync_error);
        $this->assertSame('1.0.0', $package->latest_version);
    }

    /**
     * Whether a page had a successor is cached beside its refs, because a 304
     * says nothing about pagination either — and a revalidated first page that
     * forgot there was a second would drop every ref on it.
     */
    public function test_a_revalidated_page_still_knows_a_second_page_follows(): void
    {
        $pages = [
            array_map(fn (int $i): array => [
                'name' => "v1.0.{$i}",
                'commit' => ['sha' => str_pad((string) $i, 40, '0', STR_PAD_LEFT)],
            ], range(1, 100)),
            [['name' => 'v1.1.0', 'commit' => ['sha' => str_repeat('b', 40)]]],
        ];

        $this->fakeGitHub([
            'api.github.com/repos/acme/widgets/tags*' => function ($request) use ($pages) {
                $page = (int) ($request->data()['page'] ?? 1);
                $etag = "\"tags-page-{$page}\"";

                return $request->hasHeader('If-None-Match', $etag)
                    ? Http::response('', 304)
                    : Http::response($pages[$page - 1] ?? [], 200, ['ETag' => $etag]);
            },
            'api.github.com/repos/acme/widgets/branches*' => Http::response([]),
        ]);

        $package = $this->makePackage();

        app(PackageSynchronizer::class)->sync($package);

        $this->assertSame(101, $package->versions()->count());

        app(PackageSynchronizer::class)->sync($package);

        // The second page was asked for again — conditionally — rather than
        // being forgotten with the body of the first.
        $this->assertSame(101, $package->versions()->count());

        $revalidated = collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/tags')
                && $pair[0]->hasHeader('If-None-Match'))
            ->count();

        $this->assertSame(2, $revalidated);
    }

    public function test_gitlab_listings_are_revalidated_too(): void
    {
        Http::fake([
            'gitlab.com/api/v4/projects/group%2Fwidgets/repository/tags*' => fn ($request) => $request->hasHeader('If-None-Match', '"gl-tags-1"')
                ? Http::response('', 304)
                : Http::response([
                    ['name' => 'v1.0.0', 'commit' => ['id' => str_repeat('a', 40)]],
                ], 200, ['ETag' => '"gl-tags-1"']),
            'gitlab.com/api/v4/projects/group%2Fwidgets/repository/branches*' => Http::response([]),
            'gitlab.com/api/v4/projects/group%2Fwidgets/repository/files/composer.json/raw*' => Http::response(
                json_encode(['name' => 'group/widgets', 'type' => 'library']),
            ),
            'gitlab.com/api/v4/projects/group%2Fwidgets/repository/commits/*' => Http::response([
                'committed_date' => '2026-02-01T12:00:00Z',
            ]),
            'gitlab.com/api/v4/projects/group%2Fwidgets/repository/archive.zip*' => fn () => Http::response(
                Zipball::bytes(), 200, ['Content-Type' => 'application/zip'],
            ),
            'gitlab.com/api/v4/projects/group%2Fwidgets' => Http::response(['default_branch' => 'main']),
        ]);

        $package = Package::factory()->unreleased()->create([
            'name' => 'group/widgets',
            'repository' => 'https://gitlab.com/group/widgets',
            'token' => 'glpat-secret',
        ]);

        app(PackageSynchronizer::class)->sync($package);
        app(PackageSynchronizer::class)->sync($package);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/repository/tags')
            && $request->hasHeader('If-None-Match', '"gl-tags-1"'));

        $this->assertSame(['1.0.0'], $package->versions()->pluck('version')->all());
    }
}
