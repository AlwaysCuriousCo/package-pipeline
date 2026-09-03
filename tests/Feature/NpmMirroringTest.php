<?php

namespace Tests\Feature;

use App\Enums\Ecosystem;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Upstream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * npm upstream mirroring: serving packages this registry does not publish out
 * of an npm registry, cached here.
 *
 * Http::preventStrayRequests() is on suite-wide, so "makes no network call"
 * needs no mock to assert — a test that stubs nothing proves nothing was
 * fetched.
 */
class NpmMirroringTest extends TestCase
{
    use RefreshDatabase;

    private const UPSTREAM = 'https://npm.upstream.test';

    private const TARBALL = 'https://tarballs.upstream.test/lodash/-/lodash-4.17.21.tgz';

    private const BYTES = 'pretend-this-is-a-tgz';

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.dists' => 's3']);
        Storage::fake('s3');
    }

    /**
     * The default repository, mirroring one npm upstream.
     */
    private function mirroring(): Repository
    {
        $repository = Repository::default();

        Upstream::factory()->create([
            'repository_id' => $repository->getKey(),
            'name' => 'npmjs.org',
            'url' => self::UPSTREAM,
            'ecosystem' => Ecosystem::Npm,
        ]);

        return $repository->refresh();
    }

    /**
     * An abbreviated packument in the shape registry.npmjs.org serves one.
     *
     * @return array<string, mixed>
     */
    private function upstreamPackument(): array
    {
        return [
            'name' => 'lodash',
            'dist-tags' => ['latest' => '4.17.21'],
            'versions' => [
                '4.17.21' => [
                    'name' => 'lodash',
                    'version' => '4.17.21',
                    'dependencies' => [],
                    'dist' => [
                        'tarball' => self::TARBALL,
                        'shasum' => sha1(self::BYTES),
                        'integrity' => 'sha512-'.base64_encode(hash('sha512', self::BYTES, true)),
                    ],
                ],
            ],
        ];
    }

    public function test_a_mirrored_packument_points_its_tarballs_back_here(): void
    {
        $this->mirroring();

        Http::fake(['npm.upstream.test/lodash' => Http::response($this->upstreamPackument())]);

        $response = $this->getJson('/npm/lodash')->assertOk();

        $manifest = $response->json('versions')['4.17.21'];

        $this->assertSame(url('/npm/lodash/-/lodash-4.17.21.tgz'), $manifest['dist']['tarball']);
        // The digests ride along untouched — they are what the tarball is
        // verified against before this registry ever serves it.
        $this->assertSame(sha1(self::BYTES), $manifest['dist']['shasum']);
        $this->assertSame('4.17.21', $response->json('dist-tags')['latest']);

        $this->assertDatabaseHas('mirrored_packages', ['name' => 'lodash', 'is_dev' => false]);
    }

    public function test_a_mirrored_tarball_is_fetched_verified_stored_and_reused(): void
    {
        $this->mirroring();

        Http::fake([
            'npm.upstream.test/lodash' => Http::response($this->upstreamPackument()),
            'tarballs.upstream.test/*' => Http::response(self::BYTES),
        ]);

        $this->getJson('/npm/lodash')->assertOk();

        $this->get('/npm/lodash/-/lodash-4.17.21.tgz')->assertOk();
        $this->get('/npm/lodash/-/lodash-4.17.21.tgz')->assertOk();

        $this->assertDatabaseHas('mirrored_archives', ['name' => 'lodash', 'reference' => 'lodash-4.17.21.tgz']);

        // Fetched once; the second download was answered from the stored copy.
        $this->assertCount(1, Http::recorded(
            fn (Request $request): bool => str_starts_with($request->url(), 'https://tarballs.upstream.test/'),
        ));
    }

    public function test_a_tarball_that_does_not_match_its_integrity_is_refused(): void
    {
        $this->mirroring();

        Http::fake([
            'npm.upstream.test/lodash' => Http::response($this->upstreamPackument()),
            'tarballs.upstream.test/*' => Http::response('not-the-published-bytes'),
        ]);

        $this->getJson('/npm/lodash')->assertOk();

        $this->get('/npm/lodash/-/lodash-4.17.21.tgz')->assertNotFound();

        $this->assertDatabaseMissing('mirrored_archives', ['name' => 'lodash']);
    }

    public function test_a_local_package_always_wins(): void
    {
        $this->mirroring();

        $package = Package::factory()->create(['name' => 'lodash', 'ecosystem' => Ecosystem::Npm]);
        $package->versions()->create([
            'version' => '1.0.0',
            'reference' => sha1('local'),
            'is_dev' => false,
            'metadata' => ['name' => 'lodash', 'version' => '1.0.0'],
        ]);

        // No stubs: an upstream consulted here would fail the test by itself.
        $response = $this->getJson('/npm/lodash')->assertOk();

        $this->assertArrayHasKey('1.0.0', $response->json('versions'));
    }

    public function test_a_name_published_in_any_ecosystem_is_never_mirrored(): void
    {
        $this->mirroring();

        Package::factory()->create(['name' => 'lodash', 'ecosystem' => Ecosystem::Pypi]);

        // No stubs, again deliberately: the refusal must cost no network.
        $this->getJson('/npm/lodash')->assertNotFound();
    }

    public function test_a_reserved_vendor_is_never_mirrored(): void
    {
        $repository = $this->mirroring();

        $repository->reservedVendors()->create(['vendor' => 'lodash']);

        $this->getJson('/npm/lodash')->assertNotFound();
    }

    public function test_a_reserved_vendor_closes_its_npm_scope_too(): void
    {
        $repository = $this->mirroring();

        // Reserved the Composer way, as `acme` — the same vendor npm spells @acme.
        $repository->reservedVendors()->create(['vendor' => 'acme']);

        $this->getJson('/npm/@acme%2fui')->assertNotFound();
        $this->getJson('/npm/@acme/ui')->assertNotFound();
    }

    public function test_a_composer_upstream_is_not_consulted_for_npm(): void
    {
        $repository = Repository::default();

        Upstream::factory()->create([
            'repository_id' => $repository->getKey(),
            'url' => self::UPSTREAM,
            // A Composer upstream, so the npm surface has nothing to mirror
            // from — and must not ask packagist.org about lodash.
        ]);

        $this->getJson('/npm/lodash')->assertNotFound();
    }

    public function test_an_upstream_404_is_remembered_as_a_negative_entry(): void
    {
        $this->mirroring();

        Http::fake(['npm.upstream.test/no-such-package' => Http::response('', 404)]);

        $this->getJson('/npm/no-such-package')->assertNotFound();
        $this->getJson('/npm/no-such-package')->assertNotFound();

        $this->assertDatabaseHas('mirrored_packages', ['name' => 'no-such-package', 'payload' => null]);

        // Asked once; the second miss was answered from the negative entry.
        $this->assertCount(1, Http::recorded(
            fn (Request $request): bool => str_contains($request->url(), 'no-such-package'),
        ));
    }
}
