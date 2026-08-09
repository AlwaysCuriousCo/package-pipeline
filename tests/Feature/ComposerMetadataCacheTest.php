<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\DeployToken;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Token;
use App\Models\User;
use App\Services\PackageSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Conditional requests on the `/p2` metadata endpoint.
 *
 * Composer's downloader sends If-Modified-Since on every metadata refetch, so
 * this is where a `composer update` against an unchanged registry stops paying
 * for a rebuilt, re-transferred payload per package — without ever letting a
 * 304 answer a question the requester was not allowed to ask.
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
