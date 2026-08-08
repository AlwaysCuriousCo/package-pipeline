<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Composer endpoints are mounted once per repository — at the root for
 * the default repository and under /r/{path} for named ones — and each mount
 * must behave as its own registry.
 */
class ComposerRepositoryScopingTest extends TestCase
{
    use RefreshDatabase;

    private Repository $internal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->internal = Repository::factory()->public()->create(['path' => 'internal']);
    }

    /**
     * A package in the default repository and one in /r/internal, sharing a
     * name and a VCS URL — which the per-repository unique indexes must allow.
     */
    private function makeTwinPackages(): void
    {
        Package::factory()
            ->create(['name' => 'acme/widgets', 'repository' => 'https://github.com/acme/widgets'])
            ->versions()->create([
                'version' => 'v1.0.0',
                'reference' => str_repeat('a', 40),
                'is_dev' => false,
                'metadata' => ['name' => 'acme/widgets', 'version' => 'v1.0.0'],
            ]);

        Package::factory()
            ->create([
                'name' => 'acme/widgets',
                'repository' => 'https://github.com/acme/widgets',
                'repository_id' => $this->internal->id,
                'description' => 'The internal build.',
            ])
            ->versions()->create([
                'version' => 'v2.0.0',
                'reference' => str_repeat('b', 40),
                'is_dev' => false,
                'metadata' => ['name' => 'acme/widgets', 'version' => 'v2.0.0'],
            ]);
    }

    public function test_a_package_created_without_a_repository_lands_in_the_default_one(): void
    {
        $package = Package::factory()->create();

        $this->assertTrue($package->composerRepository->isDefault());
        $this->assertTrue($package->composerRepository->public);
    }

    public function test_the_same_package_name_resolves_independently_per_repository(): void
    {
        $this->makeTwinPackages();

        $this->get('/p2/acme/widgets.json')
            ->assertOk()
            ->assertJsonPath('packages.acme/widgets.0.version', 'v1.0.0');

        $this->get('/r/internal/p2/acme/widgets.json')
            ->assertOk()
            ->assertJsonPath('packages.acme/widgets.0.version', 'v2.0.0');
    }

    public function test_list_and_search_only_see_the_repositorys_own_packages(): void
    {
        $this->makeTwinPackages();

        Package::factory()->create(['name' => 'acme/gadgets', 'repository_id' => $this->internal->id])
            ->versions()->create([
                'version' => 'v1.0.0',
                'reference' => str_repeat('c', 40),
                'is_dev' => false,
                'metadata' => ['name' => 'acme/gadgets', 'version' => 'v1.0.0'],
            ]);

        $this->get('/list.json')
            ->assertOk()
            ->assertExactJson(['packageNames' => ['acme/widgets']]);

        $this->get('/r/internal/list.json')
            ->assertOk()
            ->assertExactJson(['packageNames' => ['acme/gadgets', 'acme/widgets']]);

        $this->assertSame(
            'The internal build.',
            $this->get('/r/internal/search.json?q=acme/w')->assertOk()->json('results.0.description'),
        );
    }

    public function test_a_named_repositorys_root_advertises_its_own_mount(): void
    {
        $this->get('/r/internal/packages.json')
            ->assertOk()
            ->assertJson([
                'metadata-url' => '/r/internal/p2/%package%.json',
                'search' => url('/r/internal/search.json').'?q=%query%&type=%type%',
                'list' => url('/r/internal/list.json'),
            ]);
    }

    public function test_metadata_dist_urls_point_into_the_repositorys_mount(): void
    {
        $this->makeTwinPackages();

        $url = $this->get('/r/internal/p2/acme/widgets.json')->assertOk()
            ->json('packages.acme/widgets.0.dist.url');

        $this->assertStringContainsString('/r/internal/dist/acme/widgets/'.str_repeat('b', 40).'.zip', $url);
    }

    public function test_dist_does_not_serve_another_repositorys_reference(): void
    {
        $this->makeTwinPackages();

        // The internal build's reference is not a version of the default
        // repository's package, twin name or not.
        $this->get('/dist/acme/widgets/'.str_repeat('b', 40).'.zip')->assertNotFound();
    }

    public function test_an_unknown_repository_path_is_a_404_on_every_endpoint(): void
    {
        $this->makeTwinPackages();

        $this->get('/r/nope/packages.json')->assertNotFound();
        $this->get('/r/nope/list.json')->assertNotFound();
        $this->get('/r/nope/search.json?q=acme')->assertNotFound();
        $this->get('/r/nope/p2/acme/widgets.json')->assertNotFound();
        $this->get('/r/nope/dist/acme/widgets/'.str_repeat('b', 40).'.zip')->assertNotFound();
    }

    public function test_install_commands_point_at_the_repositorys_mount(): void
    {
        $this->makeTwinPackages();

        $package = $this->internal->packages()->sole();
        $commands = $package->installCommands();

        $this->assertStringContainsString(url('/r/internal'), $commands['repository']);
        // The config key carries the path, so two repositories on the same
        // registry never overwrite each other's entry in composer.json.
        $this->assertStringContainsString('-internal composer', $commands['repository']);

        $default = Repository::default()->packages()->sole();

        $this->assertStringNotContainsString('/r/', $default->installCommands()['repository']);
    }
}
