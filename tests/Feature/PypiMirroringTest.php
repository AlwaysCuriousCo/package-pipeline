<?php

namespace Tests\Feature;

use App\Enums\Ecosystem;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Upstream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PyPI upstream mirroring: the PEP 691 project page cached from an upstream
 * index, re-served as this registry's own PEP 503 HTML, and the files behind
 * it fetched, sha256-verified and stored on demand.
 */
class PypiMirroringTest extends TestCase
{
    use RefreshDatabase;

    private const UPSTREAM = 'https://pypi.upstream.test/simple';

    private const WHEEL = 'requests-2.31.0-py3-none-any.whl';

    private const BYTES = 'pretend-this-is-a-wheel';

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.dists' => 's3']);
        Storage::fake('s3');
    }

    private function mirroring(): Repository
    {
        $repository = Repository::default();

        Upstream::factory()->create([
            'repository_id' => $repository->getKey(),
            'name' => 'pypi.org',
            'url' => self::UPSTREAM,
            'ecosystem' => Ecosystem::Pypi,
        ]);

        return $repository->refresh();
    }

    /**
     * A project page in the PEP 691 shape pypi.org serves one.
     *
     * @return array<string, mixed>
     */
    private function upstreamProject(): array
    {
        return [
            'name' => 'requests',
            'files' => [
                [
                    'filename' => self::WHEEL,
                    'url' => 'https://files.upstream.test/'.self::WHEEL,
                    'hashes' => ['sha256' => hash('sha256', self::BYTES)],
                    'requires-python' => '>=3.7',
                ],
                [
                    // No sha256, so this registry will not re-serve it: the
                    // anchor keeps the upstream's own URL.
                    'filename' => 'requests-2.31.0.tar.gz',
                    'url' => 'https://files.upstream.test/requests-2.31.0.tar.gz',
                    'hashes' => [],
                    'yanked' => 'built on a compromised runner',
                ],
            ],
        ];
    }

    public function test_a_mirrored_project_page_points_its_verifiable_files_back_here(): void
    {
        $this->mirroring();

        Http::fake(['pypi.upstream.test/simple/requests/' => Http::response($this->upstreamProject())]);

        $page = (string) $this->get('/pypi/simple/requests/')->assertOk()->getContent();

        $this->assertStringContainsString(
            e(url('/pypi/files/requests/-/'.self::WHEEL)).'#sha256='.hash('sha256', self::BYTES),
            $page,
        );
        $this->assertStringContainsString('data-requires-python="&gt;=3.7"', $page);

        // The unverifiable file keeps the upstream's URL, and its yanked
        // reason rides along for pip's resolver.
        $this->assertStringContainsString('https://files.upstream.test/requests-2.31.0.tar.gz', $page);
        $this->assertStringContainsString('data-yanked="built on a compromised runner"', $page);

        $this->assertDatabaseHas('mirrored_packages', ['name' => 'requests']);
    }

    public function test_a_mirrored_file_is_fetched_verified_stored_and_served(): void
    {
        $this->mirroring();

        Http::fake([
            'pypi.upstream.test/simple/requests/' => Http::response($this->upstreamProject()),
            'files.upstream.test/*' => Http::response(self::BYTES),
        ]);

        $this->get('/pypi/simple/requests/')->assertOk();

        $this->get('/pypi/files/requests/-/'.self::WHEEL)
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename='.self::WHEEL);

        $this->assertDatabaseHas('mirrored_archives', ['name' => 'requests', 'reference' => self::WHEEL]);
    }

    public function test_a_file_that_does_not_match_its_sha256_is_refused(): void
    {
        $this->mirroring();

        Http::fake([
            'pypi.upstream.test/simple/requests/' => Http::response($this->upstreamProject()),
            'files.upstream.test/*' => Http::response('not-the-published-bytes'),
        ]);

        $this->get('/pypi/simple/requests/')->assertOk();

        $this->get('/pypi/files/requests/-/'.self::WHEEL)->assertNotFound();

        $this->assertDatabaseMissing('mirrored_archives', ['name' => 'requests']);
    }

    public function test_a_name_published_in_any_ecosystem_is_never_mirrored(): void
    {
        $this->mirroring();

        Package::factory()->create(['name' => 'requests', 'ecosystem' => Ecosystem::Npm]);

        // No stubs: the refusal must cost no network.
        $this->get('/pypi/simple/requests/')->assertNotFound();
    }

    public function test_an_npm_upstream_is_not_consulted_for_python(): void
    {
        $repository = Repository::default();

        Upstream::factory()->create([
            'repository_id' => $repository->getKey(),
            'url' => 'https://npm.upstream.test',
            'ecosystem' => Ecosystem::Npm,
        ]);

        $this->get('/pypi/simple/requests/')->assertNotFound();
    }
}
