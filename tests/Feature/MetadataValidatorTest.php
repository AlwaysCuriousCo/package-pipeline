<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Services\PackageSynchronizer;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The one rule `packages.updated_at` has to obey, from both directions.
 *
 * It is bookkeeping and it is the fingerprint every `/p2` response's
 * Last-Modified and ETag — and so the rendered payload cache's key — is cut
 * from. Those two jobs pull against each other, and both ways of getting it
 * wrong are silent for days:
 *
 * - Loud bookkeeping. The hourly sync stamps `last_synced_at` on every package
 *   whether or not a ref moved, so every ETag in the registry changed on the
 *   hour, capping every 304 and every cached payload at an hour.
 * - Quiet content. A deleted version leaves no timestamp behind, so the
 *   validator moved *backwards* — and Symfony compares If-Modified-Since with
 *   `>=`, so a client that kept the older date 304s forever and is never told
 *   the version is gone.
 */
class MetadataValidatorTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/p2/acme/widgets.json';

    /** @var list<string> */
    private array $tags = ['v1.0.0'];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Storage::fake(config('filesystems.dists'));

        Http::fake([
            'api.github.com/repos/acme/widgets/tags*' => fn (): PromiseInterface => Http::response(
                array_map(
                    fn (string $tag): array => ['name' => $tag, 'commit' => ['sha' => sha1($tag)]],
                    $this->tags,
                ),
            ),
            'api.github.com/repos/acme/widgets/branches*' => Http::response([]),
            'api.github.com/repos/acme/widgets/contents/composer.json*' => Http::response([
                'name' => 'acme/widgets',
                'type' => 'library',
            ]),
            'api.github.com/repos/acme/widgets/commits/*' => Http::response([
                'commit' => ['committer' => ['date' => '2026-02-01T12:00:00Z']],
            ]),
            'api.github.com/repos/acme/widgets/zipball/*' => Http::response('zip-bytes', 200, [
                'Content-Type' => 'application/zip',
            ]),
        ]);
    }

    private function syncedPackage(): Package
    {
        $package = Package::factory()->unreleased()->create([
            'name' => 'acme/widgets',
            'repository' => 'https://github.com/acme/widgets',
        ]);

        app(PackageSynchronizer::class)->sync($package);

        return $package->refresh();
    }

    /**
     * The validators a client would have kept from a first fetch.
     *
     * @return array{modifiedSince: string, etag: string}
     */
    private function validators(): array
    {
        $response = $this->get(self::URL)->assertOk();

        return [
            'modifiedSince' => (string) $response->headers->get('Last-Modified'),
            'etag' => (string) $response->headers->get('ETag'),
        ];
    }

    public function test_an_hourly_sync_that_finds_nothing_new_changes_no_validator(): void
    {
        $package = $this->syncedPackage();

        $before = $this->validators();

        // The schedule comes back an hour later and the repository has not
        // moved: the sync writes last_synced_at, and that must be all it does
        // as far as anybody holding a copy is concerned.
        $this->travel(1)->hours();

        app(PackageSynchronizer::class)->sync($package);

        $this->assertNotNull($package->fresh()?->last_synced_at);

        $after = $this->get(self::URL, ['If-Modified-Since' => $before['modifiedSince']])
            ->assertStatus(304);

        $this->assertSame($before['etag'], $after->headers->get('ETag'));
    }

    /**
     * The panel's version DeleteAction, which is a plain model delete and the
     * one removal path that saved nothing else.
     */
    public function test_deleting_a_version_invalidates_the_clients_copy(): void
    {
        $package = $this->syncedPackage();

        $package->versions()->create([
            'version' => '0.9.0',
            'order' => '0.9.0.0',
            'reference' => str_repeat('c', 40),
            'is_dev' => false,
            'metadata' => ['name' => 'acme/widgets', 'version' => '0.9.0'],
        ]);

        $before = $this->validators();

        // HTTP dates are second-resolution, so the change has to land in a
        // later second than the one the client was told about.
        $this->travel(1)->minutes();

        $package->versions()->where('version', '0.9.0')->first()?->delete();

        $this->get(self::URL, ['If-Modified-Since' => $before['modifiedSince']])
            ->assertOk()
            ->assertJsonCount(1, 'packages.acme/widgets');
    }

    /**
     * A prune whose sync then dies before finalize() ever runs — a discovery
     * that lists refs, drops the ones gone from upstream, and throws. Nothing
     * after it saves the package, so the prune has to speak for itself.
     */
    public function test_a_prune_moves_the_validator_without_a_finalize(): void
    {
        $package = $this->syncedPackage();

        $package->versions()->create([
            'version' => '0.9.0',
            'order' => '0.9.0.0',
            'reference' => str_repeat('c', 40),
            'is_dev' => false,
            'metadata' => ['name' => 'acme/widgets', 'version' => '0.9.0'],
        ]);

        $before = $this->validators();

        $this->travel(1)->minutes();

        app(PackageSynchronizer::class)->prune($package, ['1.0.0']);

        $this->get(self::URL, ['If-Modified-Since' => $before['modifiedSince']])
            ->assertOk()
            ->assertJsonCount(1, 'packages.acme/widgets');
    }

    public function test_a_prune_that_removes_nothing_changes_no_validator(): void
    {
        $package = $this->syncedPackage();

        $before = $this->validators();

        $this->travel(1)->hours();

        app(PackageSynchronizer::class)->prune($package, ['1.0.0']);

        $this->get(self::URL, ['If-Modified-Since' => $before['modifiedSince']])
            ->assertStatus(304);
    }

    /**
     * The counters live on the same rows the validators are cut from, and a
     * download is not a change to what the package publishes — so the opt-out
     * RecordDownload takes has to survive the version hook added beside it.
     */
    public function test_a_download_counter_does_not_reach_the_package_timestamp(): void
    {
        $package = $this->syncedPackage();

        $before = $this->validators();

        $this->travel(1)->hours();

        PackageVersion::withoutTimestampsOn([Package::class, PackageVersion::class], function () use ($package): void {
            $package->versions()->firstOrFail()->increment('total_downloads');
        });

        $this->get(self::URL, ['If-Modified-Since' => $before['modifiedSince']])
            ->assertStatus(304);
    }
}
