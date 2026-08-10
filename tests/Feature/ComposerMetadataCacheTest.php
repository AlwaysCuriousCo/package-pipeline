<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\DeployToken;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Token;
use App\Models\User;
use App\Services\PackageSynchronizer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Conditional requests and the rendered-payload cache on the `/p2` metadata
 * endpoint.
 *
 * Composer's downloader sends If-Modified-Since on every metadata refetch, so
 * this is where a `composer update` against an unchanged registry stops paying
 * for a rebuilt, re-transferred payload per package — without ever letting a
 * 304, or a cache hit, answer a question the requester was not allowed to ask.
 */
class ComposerMetadataCacheTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/p2/acme/widgets.json';

    private function makeServedPackage(): Package
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        $package->versions()->create([
            'version' => '1.0.0',
            'order' => '1.0.0.0',
            'reference' => str_repeat('a', 40),
            'is_dev' => false,
            'released_at' => '2026-02-01 12:00:00',
            'archive_path' => 'packages/acme/widgets/v100.zip',
            'shasum' => sha1('zip-bytes'),
            'metadata' => ['name' => 'acme/widgets', 'version' => '1.0.0'],
        ]);

        return $package;
    }

    /**
     * The Last-Modified a client would have kept from a first fetch.
     */
    private function lastModified(string $url = self::URL): string
    {
        return (string) $this->get($url)->assertOk()->headers->get('Last-Modified');
    }

    public function test_metadata_carries_validators_and_a_revalidate_only_cache_control(): void
    {
        $this->makeServedPackage();

        $response = $this->get(self::URL)->assertOk();

        $this->assertNotNull($response->headers->get('Last-Modified'));

        // Weak, because the tag comes from a fingerprint of the rows rather
        // than from a digest of the bytes.
        $this->assertStringStartsWith('W/"', (string) $response->headers->get('ETag'));

        // Without an explicit no-cache, a response carrying Last-Modified and
        // no max-age is fair game for heuristic freshness — which is how a
        // revoked token keeps being served from somebody's cache.
        $this->assertSame('no-cache, private', $response->headers->get('Cache-Control'));
        $this->assertSame('Authorization', $response->headers->get('Vary'));
    }

    public function test_an_up_to_date_client_is_answered_304_without_a_body(): void
    {
        $this->makeServedPackage();

        $response = $this->get(self::URL, ['If-Modified-Since' => $this->lastModified()])
            ->assertStatus(304);

        $this->assertSame('', $response->getContent());
    }

    public function test_an_up_to_date_client_is_answered_304_from_its_etag(): void
    {
        $this->makeServedPackage();

        $etag = (string) $this->get(self::URL)->assertOk()->headers->get('ETag');

        $this->get(self::URL, ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_a_new_version_invalidates_the_clients_copy(): void
    {
        $package = $this->makeServedPackage();

        $modifiedSince = $this->lastModified();

        // HTTP dates are second-resolution, so a change has to land in a later
        // second than the one the client was told about to be visible at all.
        $this->travel(1)->minutes();

        $package->versions()->create([
            'version' => '1.1.0',
            'order' => '1.1.0.0',
            'reference' => str_repeat('b', 40),
            'is_dev' => false,
            'metadata' => ['name' => 'acme/widgets', 'version' => '1.1.0'],
        ]);

        $this->get(self::URL, ['If-Modified-Since' => $modifiedSince])
            ->assertOk()
            ->assertJsonPath('packages.acme/widgets.0.version', '1.1.0');
    }

    public function test_a_pruned_version_invalidates_the_clients_copy(): void
    {
        // A deletion leaves no timestamp behind, so this is the case the
        // package's own updated_at is in the Last-Modified for.
        $package = $this->makeServedPackage();

        $package->versions()->create([
            'version' => '0.9.0',
            'order' => '0.9.0.0',
            'reference' => str_repeat('c', 40),
            'is_dev' => false,
            'metadata' => ['name' => 'acme/widgets', 'version' => '0.9.0'],
        ]);

        $modifiedSince = $this->lastModified();

        $this->travel(1)->minutes();

        // The real path: the tag is gone upstream, so the sync prunes it and
        // finalize() saves the package on the way out.
        $synchronizer = app(PackageSynchronizer::class);
        $synchronizer->prune($package, ['1.0.0']);
        $synchronizer->finalize($package, ['1.0.0', '0.9.0']);

        $this->get(self::URL, ['If-Modified-Since' => $modifiedSince])
            ->assertOk()
            ->assertJsonCount(1, 'packages.acme/widgets');
    }

    /**
     * PAYLOAD_REVISION exists to tell a client that what it holds predates a
     * deploy that changed the rendering — and it only ever reached the ETag,
     * which Composer's metadata downloader never sends back. Paired with a
     * date it reaches the one validator that is actually spoken.
     */
    public function test_a_copy_older_than_the_deploy_is_never_confirmed_fresh(): void
    {
        $package = $this->makeServedPackage();

        // Rows nothing has touched in years, which is every package in a
        // registry that is simply working.
        DB::table('packages')->where('id', $package->id)->update(['updated_at' => '2020-01-01 00:00:00']);
        DB::table('package_versions')->where('package_id', $package->id)->update(['updated_at' => '2020-01-01 00:00:00']);

        $this->get(self::URL, ['If-Modified-Since' => 'Wed, 01 Jan 2020 00:00:00 GMT'])->assertOk();
    }

    /**
     * The dist URLs a client keeps are built from the mount, so a repository
     * served somewhere else serves different bytes from identical rows. The
     * ETag catches that through the base it folds in; Last-Modified has nowhere
     * to put a URL, so it takes the repository's own timestamp instead — and
     * Composer sends only If-Modified-Since for /p2.
     */
    public function test_moving_the_repository_invalidates_the_clients_copy(): void
    {
        $internal = Repository::factory()->create(['path' => 'internal', 'public' => true]);

        $this->makeServedPackage()->update(['repository_id' => $internal->id]);

        $modifiedSince = $this->lastModified('/r/internal/p2/acme/widgets.json');

        $this->travel(1)->minutes();

        $internal->update(['path' => 'private']);

        $this->get('/r/private/p2/acme/widgets.json', ['If-Modified-Since' => $modifiedSince])
            ->assertOk();
    }

    /**
     * Timestamps are second-resolution and the payload cache holds entries for
     * a week, so a rename landing in the same second as the write before it
     * would leave both the validator and the cache key untouched — and the
     * registry would go on serving the old name for days. Not hypothetical:
     * PackageSynchronizer::resolveComposerName() renames a package during its
     * first sync, in the run that writes its version rows.
     */
    public function test_a_rename_moves_the_validator_without_the_timestamp(): void
    {
        $package = $this->makeServedPackage();

        $etag = (string) $this->get(self::URL)->assertOk()->headers->get('ETag');

        Package::withoutTimestamps(fn () => $package->forceFill(['name' => 'acme/gadgets'])->save());

        $renamed = $this->get('/p2/acme/gadgets.json')->assertOk();

        $this->assertNotSame($etag, $renamed->headers->get('ETag'));
        $this->assertSame('acme/gadgets', array_key_first((array) $renamed->json('packages')));
    }

    /**
     * The served document is a function of the stored rows, and no validator
     * here is cut from the bytes — so a setting that rewrote every document in
     * the registry would do it while every client went on revalidating happily
     * against the copy it already had.
     */
    public function test_release_dates_are_rendered_in_utc_whatever_the_app_timezone(): void
    {
        $original = date_default_timezone_get();

        config(['app.timezone' => 'America/Chicago']);
        date_default_timezone_set('America/Chicago');

        try {
            $this->makeServedPackage();

            $this->assertStringEndsWith(
                '+00:00',
                (string) $this->get(self::URL)->assertOk()->json('packages.acme/widgets.0.time'),
            );
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_dev_and_release_metadata_are_validated_apart(): void
    {
        $package = $this->makeServedPackage();

        $modifiedSince = $this->lastModified();

        $this->travel(1)->minutes();

        $package->versions()->create([
            'version' => 'dev-main',
            'reference' => str_repeat('d', 40),
            'is_dev' => true,
            'metadata' => ['name' => 'acme/widgets', 'version' => 'dev-main'],
        ]);

        // A branch moving is not news to a client holding the release list;
        // only the package row's own timestamp is shared between the two.
        $this->get('/p2/acme/widgets~dev.json', ['If-Modified-Since' => $modifiedSince])->assertOk();
    }

    public function test_downloading_an_archive_does_not_invalidate_metadata(): void
    {
        // The download counters live on the same rows the validators are cut
        // from; bumping them must not read as the package having changed, or
        // the busiest packages would revalidate to a full body every time.
        config(['filesystems.dists' => 's3']);
        Storage::fake('s3');
        Storage::disk('s3')->put('packages/acme/widgets/v100.zip', 'zip-bytes');

        $package = $this->makeServedPackage();

        $modifiedSince = $this->lastModified();

        $this->travel(1)->minutes();

        $this->get('/dist/acme/widgets/'.str_repeat('a', 40).'.zip')->assertOk();

        $this->assertSame(1, $package->fresh()->total_downloads);

        $this->get(self::URL, ['If-Modified-Since' => $modifiedSince])->assertStatus(304);
    }

    /**
     * The dist base is folded into the ETag, and the ETag is the payload
     * cache's key — so a base taken from the request would let an anonymous
     * loop mint a cache entry per forged header, each holding megabytes for a
     * week, and hand the victim's Composer archive URLs on a host of the
     * attacker's choosing.
     */
    public function test_a_forged_host_changes_neither_the_dist_urls_nor_the_validator(): void
    {
        $this->makeServedPackage();

        $expected = $this->get(self::URL)->assertOk();

        $forged = $this->get(self::URL, [
            'Host' => 'evil.example',
            // Every proxy is trusted, so this is as much an input as Host is.
            'X-Forwarded-Host' => 'evil.example',
        ])->assertOk();

        $this->assertStringStartsWith(
            rtrim((string) config('app.url'), '/').'/dist/',
            $forged->json('packages.acme/widgets.0.dist.url'),
        );

        $this->assertSame($expected->headers->get('ETag'), $forged->headers->get('ETag'));
    }

    public function test_a_client_without_visibility_gets_404_rather_than_304(): void
    {
        $internal = Repository::factory()->create(['path' => 'internal', 'public' => false]);

        $package = $this->makeServedPackage();
        $package->update(['repository_id' => $internal->id]);

        $granted = DeployToken::factory()->create();
        $granted->packages()->attach($package);
        $grantedToken = Token::issue($granted, 'granted', [TokenAbility::RepositoryRead]);

        $modifiedSince = (string) $this->withToken($grantedToken->plainText)
            ->get('/r/internal/p2/acme/widgets.json')
            ->assertOk()
            ->headers->get('Last-Modified');

        // A live token that simply may not see this package. A 304 would
        // confirm the package exists, and confirm its metadata is unchanged.
        $stranger = DeployToken::factory()->create();
        $stranger->repositories()->attach(Repository::factory()->create(['path' => 'other', 'public' => false]));
        $strangerToken = Token::issue($stranger, 'stranger', [TokenAbility::RepositoryRead]);

        $this->withToken($strangerToken->plainText)
            ->get('/r/internal/p2/acme/widgets.json', ['If-Modified-Since' => $modifiedSince])
            ->assertNotFound();
    }

    /**
     * Rewrite the served version name underneath the cache, without moving
     * anything the cache key is cut from. A response that still reads 1.0.0
     * was handed back whole; one that reads 9.9.9 was rebuilt from the rows.
     */
    private function rewriteBehindTheCache(Package $package): void
    {
        DB::table('package_versions')
            ->where('package_id', $package->id)
            ->update(['metadata' => json_encode(['name' => 'acme/widgets', 'version' => '9.9.9'])]);
    }

    public function test_the_rendered_payload_is_reused_between_requests(): void
    {
        $package = $this->makeServedPackage();

        $this->get(self::URL)->assertOk();

        $this->rewriteBehindTheCache($package);

        $this->get(self::URL)
            ->assertOk()
            ->assertJsonPath('packages.acme/widgets.0.version', '1.0.0');
    }

    public function test_a_cache_hit_reads_no_version_rows(): void
    {
        $this->makeServedPackage();

        $this->get(self::URL)->assertOk();

        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->get(self::URL)->assertOk();

        $touched = array_values(array_filter(
            $queries,
            fn (string $sql): bool => str_contains($sql, 'package_versions'),
        ));

        // Exactly the fingerprint aggregate, and nothing that would drag every
        // version's metadata column back out of the database.
        $this->assertCount(1, $touched);
        $this->assertStringContainsString('count(*)', $touched[0]);
    }

    public function test_a_new_version_rebuilds_the_payload(): void
    {
        $package = $this->makeServedPackage();

        $this->get(self::URL)->assertOk();

        $package->versions()->create([
            'version' => '1.1.0',
            'order' => '1.1.0.0',
            'reference' => str_repeat('b', 40),
            'is_dev' => false,
            'metadata' => ['name' => 'acme/widgets', 'version' => '1.1.0'],
        ]);

        $this->get(self::URL)
            ->assertOk()
            ->assertJsonCount(2, 'packages.acme/widgets')
            ->assertJsonPath('packages.acme/widgets.0.version', '1.1.0');
    }

    public function test_a_payload_over_the_ceiling_is_served_but_never_stored(): void
    {
        // The ceiling keeps an outsized payload out of the cache store, not out
        // of the response; zero turns the cache off for every payload.
        config(['registry.metadata_cache.max_kilobytes' => 0]);

        $package = $this->makeServedPackage();

        $this->get(self::URL)->assertOk();

        $this->rewriteBehindTheCache($package);

        $this->get(self::URL)
            ->assertOk()
            ->assertJsonPath('packages.acme/widgets.0.version', '9.9.9');
    }

    public function test_the_cache_never_answers_for_a_client_that_may_not_see_the_package(): void
    {
        $internal = Repository::factory()->create(['path' => 'internal', 'public' => false]);

        $package = $this->makeServedPackage();
        $package->update(['repository_id' => $internal->id]);

        $granted = DeployToken::factory()->create();
        $granted->packages()->attach($package);

        // Warmed by a client that may see it, then asked for by one that may
        // not: the cache holds a payload, and the answer is still a 404.
        $this->withToken(Token::issue($granted, 'granted', [TokenAbility::RepositoryRead])->plainText)
            ->get('/r/internal/p2/acme/widgets.json')
            ->assertOk();

        $stranger = DeployToken::factory()->create();
        $stranger->repositories()->attach(Repository::factory()->create(['path' => 'other', 'public' => false]));

        $this->withToken(Token::issue($stranger, 'stranger', [TokenAbility::RepositoryRead])->plainText)
            ->get('/r/internal/p2/acme/widgets.json')
            ->assertNotFound();
    }

    public function test_a_revoked_token_cannot_keep_revalidating(): void
    {
        $internal = Repository::factory()->create(['path' => 'internal', 'public' => false]);

        $this->makeServedPackage()->update(['repository_id' => $internal->id]);

        $new = Token::issue(User::factory()->superAdmin()->create(), 'ci', [TokenAbility::RepositoryRead]);

        $modifiedSince = (string) $this->withToken($new->plainText)
            ->get('/r/internal/p2/acme/widgets.json')
            ->assertOk()
            ->headers->get('Last-Modified');

        $new->token->delete();

        $this->withToken($new->plainText)
            ->getJson('/r/internal/p2/acme/widgets.json', ['If-Modified-Since' => $modifiedSince])
            ->assertUnauthorized();
    }
}
