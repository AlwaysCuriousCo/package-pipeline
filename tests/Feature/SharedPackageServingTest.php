<?php

namespace Tests\Feature;

use App\Exceptions\NameCollision;
use App\Exceptions\VendorReserved;
use App\Models\Package;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

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

    public function test_deleting_a_package_clears_its_serving_rows(): void
    {
        $package = Package::factory()->create();
        $package->serveFrom([$this->internal]);

        $package->delete();

        $this->assertSame([], $this->servingRows($package));
    }
}
