<?php

namespace Tests\Feature;

use App\Enums\PageDownloads;
use App\Enums\TokenAbility;
use App\Exceptions\NameCollision;
use App\Exceptions\VendorReserved;
use App\Models\DeployToken;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Token;
use App\Models\User;
use App\Services\CreateVersionFromZip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

/**
 * One package, several Composer repositories.
 *
 * A package lives in one repository — its home, the one `repository_id` names
 * — and can be added to any number of others, which then serve the same row:
 * the same versions, the same archives, the same download counter, under their
 * own mounts.
 */
class SharedPackageServingTest extends TestCase
{
    use RefreshDatabase;

    private Repository $internal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->internal = Repository::factory()->public()->create(['path' => 'internal']);
    }

    private function released(Package $package, string $version = 'v1.0.0'): Package
    {
        $package->versions()->create([
            'version' => $version,
            'reference' => str_repeat('a', 40),
            'is_dev' => false,
            'metadata' => ['name' => $package->name, 'version' => $version],
        ]);

        return $package;
    }

    /** @return list<int> */
    private function servingRows(Package $package): array
    {
        return DB::table('package_repository')
            ->where('package_id', $package->id)
            ->orderBy('repository_id')
            ->pluck('repository_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    public function test_a_created_package_is_served_from_its_home_repository(): void
    {
        $package = Package::factory()->create();

        $this->assertSame([(int) $package->repository_id], $this->servingRows($package));
        $this->assertSame([(int) $package->repository_id], $package->servingRepositoryIds());
    }

    public function test_a_renamed_package_carries_its_name_into_every_serving_row(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);
        $package->serveFrom([$this->internal]);

        $package->update(['name' => 'acme/gadgets']);

        $this->assertSame(
            ['acme/gadgets', 'acme/gadgets'],
            DB::table('package_repository')->where('package_id', $package->id)->pluck('package_name')->all(),
        );
    }

    public function test_moving_a_package_home_stops_the_old_repository_serving_it(): void
    {
        $package = Package::factory()->create();
        $default = (int) $package->repository_id;

        $package->update(['repository_id' => $this->internal->id]);

        $this->assertSame([(int) $this->internal->id], $this->servingRows($package));
        $this->assertSame(0, Repository::query()->whereKey($default)->first()->packages()->count());
    }

    public function test_a_mount_that_was_not_given_the_package_does_not_serve_it(): void
    {
        // Public, so nothing about this is an authentication answer: the mount
        // simply does not serve the package, and knowing the name gets a
        // caller no closer to it.
        $this->released(Package::factory()->create(['name' => 'acme/widgets']));

        $this->getJson('/r/internal/p2/acme/widgets.json')
            ->assertNotFound()
            ->assertJsonPath('message', 'Package acme/widgets is not served by this repository.');

        $this->getJson('/r/internal/dist/acme/widgets/'.str_repeat('a', 40).'.zip')->assertNotFound();
        $this->get('/r/internal/list.json')->assertOk()->assertExactJson(['packageNames' => []]);
        $this->get('/r/internal/p/acme/widgets')->assertNotFound();
    }

    public function test_a_shared_package_answers_under_both_mounts(): void
    {
        $package = $this->released(Package::factory()->create(['name' => 'acme/widgets']));

        $package->serveFrom([$this->internal]);

        $this->get('/p2/acme/widgets.json')
            ->assertOk()
            ->assertJsonPath('packages.acme/widgets.0.version', 'v1.0.0');

        $this->get('/r/internal/p2/acme/widgets.json')
            ->assertOk()
            ->assertJsonPath('packages.acme/widgets.0.version', 'v1.0.0');

        $this->get('/r/internal/list.json')
            ->assertOk()
            ->assertExactJson(['packageNames' => ['acme/widgets']]);
    }

    public function test_a_dist_download_is_served_from_a_shared_mount(): void
    {
        $package = $this->released(Package::factory()->create(['name' => 'acme/widgets']));
        $package->serveFrom([$this->internal]);

        $url = $this->get('/r/internal/p2/acme/widgets.json')
            ->assertOk()
            ->json('packages.acme/widgets.0.dist.url');

        $this->assertStringContainsString('/r/internal/dist/acme/widgets/', $url);
    }

    public function test_adding_a_package_twice_changes_nothing(): void
    {
        $package = Package::factory()->create();

        $package->serveFrom([$this->internal]);
        $package->serveFrom([$this->internal, $this->internal]);

        $this->assertCount(2, $this->servingRows($package));
    }

    public function test_a_repository_refuses_a_name_it_already_serves(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);
        Package::factory()->create(['name' => 'acme/widgets', 'repository_id' => $this->internal->id]);

        $this->expectException(NameCollision::class);

        $package->serveFrom([$this->internal]);
    }

    public function test_a_reserved_vendor_refuses_the_repository_that_does_not_own_it(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        Repository::default()->reservedVendors()->create(['vendor' => 'acme']);

        $this->expectException(VendorReserved::class);

        $package->serveFrom([$this->internal]);
    }

    public function test_a_package_stops_being_served_but_never_leaves_home(): void
    {
        $package = Package::factory()->create();
        $package->serveFrom([$this->internal]);

        $package->stopServingFrom([$this->internal, $package->repository_id]);

        $this->assertSame([(int) $package->repository_id], $this->servingRows($package));
    }

    public function test_syncing_the_serving_list_adds_and_removes_in_one_step(): void
    {
        $other = Repository::factory()->create(['path' => 'other']);
        $package = Package::factory()->create();
        $package->serveFrom([$this->internal]);

        $package->syncServingRepositories([$other->id]);

        $this->assertSame(
            [(int) $package->repository_id, (int) $other->id],
            $this->servingRows($package),
        );
    }

    public function test_a_private_mount_does_not_publish_a_package_its_home_publishes(): void
    {
        $private = Repository::factory()->create(['path' => 'closed', 'public' => false]);
        $package = $this->released(Package::factory()->create(['name' => 'acme/widgets']));

        $package->serveFrom([$private]);

        // Readable where it lives, which is public...
        $this->get('/p2/acme/widgets.json')->assertOk();

        // ...and behind the private mount's own door everywhere else. A
        // package is readable through a mount, never in the abstract.
        $this->getJson('/r/closed/p2/acme/widgets.json')->assertUnauthorized();
    }

    public function test_a_public_mount_serves_a_package_that_lives_somewhere_private(): void
    {
        $private = Repository::factory()->create(['path' => 'closed', 'public' => false]);
        $package = $this->released(Package::factory()->create([
            'name' => 'acme/widgets',
            'repository_id' => $private->id,
        ]));

        $package->serveFrom([$this->internal]);

        $this->get('/r/internal/p2/acme/widgets.json')->assertOk();
        $this->getJson('/r/closed/p2/acme/widgets.json')->assertUnauthorized();
    }

    public function test_a_deploy_token_granted_a_mount_reads_what_that_mount_serves(): void
    {
        $private = Repository::factory()->create(['path' => 'closed', 'public' => false]);
        $package = $this->released(Package::factory()->create([
            'name' => 'acme/widgets',
            'repository_id' => Repository::factory()->create(['path' => 'elsewhere', 'public' => false])->id,
        ]));

        $package->serveFrom([$private]);

        $deploy = DeployToken::query()->create(['name' => 'ci']);
        $deploy->repositories()->attach($private);
        $token = Token::issue($deploy, 'ci', [TokenAbility::RepositoryRead]);

        $this->withToken($token->plainText)
            ->getJson('/r/closed/p2/acme/widgets.json')
            ->assertOk();

        // The grant is on the mount, not on the package: through the mount
        // where the package lives, this token is told nothing — a 404 rather
        // than a 403, which is how every unreadable package answers here.
        $this->withToken($token->plainText)
            ->getJson('/r/elsewhere/p2/acme/widgets.json')
            ->assertNotFound();
    }

    public function test_an_upload_through_a_shared_mount_publishes_to_the_package_it_serves(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);
        $package->serveFrom([$this->internal]);

        app(CreateVersionFromZip::class)->create(
            $this->internal,
            'acme/widgets',
            $this->zip('acme/widgets', 'v2.0.0'),
            'v2.0.0',
        );

        $this->assertSame(1, Package::query()->where('name', 'acme/widgets')->count());
        $this->assertSame('v2.0.0', $package->versions()->sole()->version);
    }

    /**
     * A zip holding nothing but a composer.json, which is all the creator
     * reads out of it.
     */
    private function zip(string $name, string $version): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pkg').'.zip';

        $archive = new ZipArchive;
        $archive->open($path, ZipArchive::CREATE);
        $archive->addFromString('composer.json', json_encode(['name' => $name, 'version' => $version]));
        $archive->close();

        return $path;
    }

    public function test_a_page_prints_the_mount_it_was_asked_for(): void
    {
        $package = $this->released(Package::factory()->create([
            'name' => 'acme/widgets',
            'page_enabled' => true,
            'page_install' => true,
        ]));

        $package->serveFrom([$this->internal]);

        $this->get('/r/internal/p/acme/widgets')
            ->assertOk()
            ->assertSee('composer config repositories.', false)
            ->assertSee(url('/r/internal'), false);

        $this->get('/p/acme/widgets')
            ->assertOk()
            ->assertSee(url('/p/acme/widgets'), false);
    }

    public function test_a_page_on_a_private_mount_offers_no_downloads(): void
    {
        $private = Repository::factory()->create(['path' => 'closed', 'public' => false]);
        $package = $this->released(Package::factory()->create([
            'name' => 'acme/widgets',
            'page_enabled' => true,
            'page_downloads' => PageDownloads::Latest,
        ]));

        $package->serveFrom([$private]);

        // Offered where the package lives, which is public...
        $this->assertSame(PageDownloads::Latest, $package->servedFrom(Repository::default())->pageDownloads());

        // ...and withheld under the private mount, whose page says instead
        // that the visitor has to ask for access.
        $this->get('/r/closed/p/acme/widgets/download')->assertNotFound();
    }

    public function test_the_sitemap_lists_a_page_under_every_mount_serving_it(): void
    {
        config(['registry.pages.sitemap' => true]);

        $package = Package::factory()->create(['name' => 'acme/widgets', 'page_enabled' => true]);
        $package->serveFrom([$this->internal]);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee(url('/p/acme/widgets'), false)
            ->assertSee(url('/r/internal/p/acme/widgets'), false);
    }

    public function test_the_api_serves_a_package_from_another_repository_and_stops_again(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        $plain = Token::issue(
            User::factory()->superAdmin()->create(),
            'provisioning',
            [TokenAbility::ApiRead, TokenAbility::ApiWrite],
        )->plainText;

        $this->withToken($plain)
            ->postJson("/api/v1/packages/{$package->id}/repositories", ['repository' => 'internal'])
            ->assertOk()
            ->assertJsonPath('data.repository.path', null)
            ->assertJsonCount(2, 'data.repositories');

        $this->assertContains((int) $this->internal->id, $package->fresh()->servingRepositoryIds());

        // And the listing filter follows what a repository serves rather than
        // what lives in it.
        $this->withToken($plain)->getJson('/api/v1/packages?repository=internal')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'acme/widgets');

        $this->withToken($plain)
            ->deleteJson("/api/v1/packages/{$package->id}/repositories", ['repository' => 'internal'])
            ->assertOk()
            ->assertJsonCount(1, 'data.repositories');

        $this->assertSame([(int) $package->repository_id], $package->fresh()->servingRepositoryIds());
    }

    public function test_the_api_refuses_to_remove_a_package_from_where_it_lives(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets', 'repository_id' => $this->internal->id]);

        $plain = Token::issue(
            User::factory()->superAdmin()->create(),
            'provisioning',
            [TokenAbility::ApiRead, TokenAbility::ApiWrite],
        )->plainText;

        $this->withToken($plain)
            ->deleteJson("/api/v1/packages/{$package->id}/repositories", ['repository' => 'internal'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('repository');
    }

    public function test_the_api_refuses_a_repository_that_already_serves_the_name(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);
        Package::factory()->create(['name' => 'acme/widgets', 'repository_id' => $this->internal->id]);

        $plain = Token::issue(
            User::factory()->superAdmin()->create(),
            'provisioning',
            [TokenAbility::ApiRead, TokenAbility::ApiWrite],
        )->plainText;

        $this->withToken($plain)
            ->postJson("/api/v1/packages/{$package->id}/repositories", ['repository' => 'internal'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('repository');
    }

    public function test_the_console_serves_a_package_from_another_repository(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        $this->artisan('package:serve acme/widgets internal')->assertSuccessful();

        $this->assertContains((int) $this->internal->id, $package->fresh()->servingRepositoryIds());

        $this->artisan('package:serve acme/widgets internal --remove')->assertSuccessful();

        $this->assertSame([(int) $package->repository_id], $package->fresh()->servingRepositoryIds());
    }

    public function test_the_console_refuses_to_remove_a_package_from_where_it_lives(): void
    {
        Package::factory()->create(['name' => 'acme/widgets', 'repository_id' => $this->internal->id]);

        $this->artisan('package:serve acme/widgets internal --remove')->assertFailed();
    }

    public function test_deleting_a_package_clears_its_serving_rows(): void
    {
        $package = Package::factory()->create();
        $package->serveFrom([$this->internal]);

        $package->delete();

        $this->assertSame([], $this->servingRows($package));
    }
}
