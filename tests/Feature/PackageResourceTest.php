<?php

namespace Tests\Feature;

use App\Enums\PageDownloads;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\Packages\Pages\CreatePackage;
use App\Filament\Resources\Packages\Pages\EditPackage;
use App\Filament\Resources\Packages\Pages\ListPackages;
use App\Jobs\RefreshPackagePage;
use App\Jobs\SyncPackageJob;
use App\Models\Package;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class PackageResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_the_package_index_lists_records(): void
    {
        $packages = Package::factory()->count(3)->create();

        Livewire::test(ListPackages::class)
            ->assertCanSeeTableRecords($packages);
    }

    public function test_a_package_can_be_created(): void
    {
        Queue::fake([SyncPackageJob::class]);

        Livewire::test(CreatePackage::class)
            ->fillForm([
                'name' => 'acme/widgets',
                'repository' => 'https://github.com/acme/widgets',
                'description' => 'Widgets for Acme.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('packages', [
            'name' => 'acme/widgets',
            'repository' => 'https://github.com/acme/widgets',
            'description' => 'Widgets for Acme.',
        ]);

        // A new package imports its versions right away rather than waiting
        // for the next push.
        Queue::assertPushed(SyncPackageJob::class, fn (SyncPackageJob $job): bool => $job->package->name === 'acme/widgets');
    }

    public function test_a_package_can_be_created_without_a_release(): void
    {
        Queue::fake([SyncPackageJob::class]);

        Livewire::test(CreatePackage::class)
            ->fillForm([
                'name' => 'acme/preview',
                'repository' => 'https://github.com/acme/preview',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('packages', [
            'name' => 'acme/preview',
            'latest_version' => null,
        ]);
    }

    public function test_arriving_from_a_source_preselects_it(): void
    {
        $source = Source::factory()->create();

        Livewire::withQueryParams(['source' => $source->getKey()]);

        Livewire::test(CreatePackage::class)
            ->assertSchemaStateSet(['source_id' => $source->getKey()]);
    }

    public function test_an_unknown_source_in_the_url_is_ignored(): void
    {
        Livewire::withQueryParams(['source' => 999]);

        Livewire::test(CreatePackage::class)
            ->assertSchemaStateSet(['source_id' => null]);
    }

    public function test_the_repository_must_be_a_unique_url(): void
    {
        Package::factory()->create(['repository' => 'https://github.com/acme/taken']);

        Livewire::test(CreatePackage::class)
            ->fillForm([
                'name' => 'acme/duplicate',
                'repository' => 'https://github.com/acme/taken',
            ])
            ->call('create')
            ->assertHasFormErrors(['repository' => 'unique']);

        Livewire::test(CreatePackage::class)
            ->fillForm([
                'name' => 'acme/bad-url',
                'repository' => 'not-a-url',
            ])
            ->call('create')
            ->assertHasFormErrors(['repository' => 'url']);
    }

    public function test_name_and_repository_are_required(): void
    {
        Livewire::test(CreatePackage::class)
            ->fillForm(['name' => null, 'repository' => null])
            ->call('create')
            ->assertHasFormErrors([
                'name' => 'required',
                'repository' => 'required',
            ]);
    }

    public function test_a_package_can_be_edited(): void
    {
        $package = Package::factory()->create();

        Livewire::test(EditPackage::class, ['record' => $package->getRouteKey()])
            ->fillForm(['latest_version' => 'v9.9.9'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('v9.9.9', $package->refresh()->latest_version);
    }

    public function test_a_package_keeps_its_own_repository_when_edited(): void
    {
        $package = Package::factory()->create();

        Livewire::test(EditPackage::class, ['record' => $package->getRouteKey()])
            ->fillForm(['name' => 'renamed/package'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('renamed/package', $package->refresh()->name);
    }

    public function test_the_table_can_be_filtered_to_unreleased_packages(): void
    {
        $released = Package::factory()->create();
        $unreleased = Package::factory()->unreleased()->create();

        Livewire::test(ListPackages::class)
            ->filterTable('unreleased')
            ->assertCanSeeTableRecords([$unreleased])
            ->assertCanNotSeeTableRecords([$released]);
    }

    public function test_the_table_can_be_filtered_to_failing_syncs(): void
    {
        $healthy = Package::factory()->create();
        $failing = Package::factory()->create(['sync_error' => 'Repository not found.']);

        Livewire::test(ListPackages::class)
            ->filterTable('sync_failing')
            ->assertCanSeeTableRecords([$failing])
            ->assertCanNotSeeTableRecords([$healthy]);
    }

    public function test_the_table_can_be_filtered_to_packages_that_stopped_syncing(): void
    {
        $fresh = Package::factory()->create(['last_synced_at' => now()->subHour()]);
        $stale = Package::factory()->create(['last_synced_at' => now()->subDays(3)]);
        $never = Package::factory()->create(['last_synced_at' => null]);
        // Nothing pulls this one, so it is not overdue for a sync.
        $uploaded = Package::factory()->create(['repository' => null, 'last_synced_at' => null]);

        Livewire::test(ListPackages::class)
            ->filterTable('stale')
            ->assertCanSeeTableRecords([$stale, $never])
            ->assertCanNotSeeTableRecords([$fresh, $uploaded]);
    }

    public function test_the_navigation_badge_counts_failing_syncs(): void
    {
        Package::factory()->create();

        $this->assertNull(PackageResource::getNavigationBadge());

        Package::factory()->count(2)->create(['sync_error' => 'Repository not found.']);

        $this->assertSame('2', PackageResource::getNavigationBadge());
    }

    public function test_selected_packages_can_be_synced_in_bulk(): void
    {
        Queue::fake([SyncPackageJob::class]);

        $packages = Package::factory()->count(3)->create();

        Livewire::test(ListPackages::class)
            ->callTableBulkAction('syncSelected', $packages)
            ->assertHasNoTableBulkActionErrors();

        Queue::assertPushed(SyncPackageJob::class, 3);
        Queue::assertPushed(SyncPackageJob::class, fn (SyncPackageJob $job): bool => $job->force === false);
    }

    public function test_a_bulk_sync_skips_packages_that_already_have_one_queued(): void
    {
        Queue::fake([SyncPackageJob::class]);

        $queued = Package::factory()->create();
        $idle = Package::factory()->create();

        // Takes the uniqueness lock the bulk action would take, exactly as a
        // webhook-triggered sync waiting on the queue holds it.
        $this->assertTrue(SyncPackageJob::dispatchUnlessPending($queued));

        Livewire::test(ListPackages::class)
            ->callTableBulkAction('syncSelected', [$queued, $idle]);

        // One from the setup above, one for the package that was free.
        Queue::assertPushed(SyncPackageJob::class, 2);
        Queue::assertPushed(SyncPackageJob::class, fn (SyncPackageJob $job): bool => $job->package->is($idle));
    }

    public function test_a_bulk_sync_skips_packages_with_nothing_to_sync_from(): void
    {
        Queue::fake([SyncPackageJob::class]);

        $uploaded = Package::factory()->create(['repository' => null]);

        Livewire::test(ListPackages::class)
            ->callTableBulkAction('syncSelected', [$uploaded]);

        Queue::assertNothingPushed();
    }

    public function test_selected_packages_can_be_rebuilt_in_bulk(): void
    {
        Queue::fake([SyncPackageJob::class]);

        $packages = Package::factory()->count(2)->create();

        Livewire::test(ListPackages::class)
            ->callTableBulkAction('rebuildSelected', $packages)
            ->assertHasNoTableBulkActionErrors();

        Queue::assertPushed(SyncPackageJob::class, 2);
        // A rebuild is a forced sync; without the flag it would skip every
        // version that still points at the same ref.
        Queue::assertPushed(SyncPackageJob::class, fn (SyncPackageJob $job): bool => $job->force === true);
    }

    public function test_the_package_pages_render_over_http(): void
    {
        $package = Package::factory()->create();

        $this->get('/admin/packages')
            ->assertOk()
            ->assertSee($package->name);

        $this->get("/admin/packages/{$package->getRouteKey()}")->assertOk();
        $this->get("/admin/packages/{$package->getRouteKey()}/edit")->assertOk();
        $this->get('/admin/packages/create')->assertOk();
    }

    public function test_the_token_entry_reflects_whether_a_token_is_stored(): void
    {
        $without = Package::factory()->create(['token' => null]);
        $with = Package::factory()->create(['token' => 'ghp_secret']);

        $this->get("/admin/packages/{$without->getRouteKey()}")
            ->assertOk()
            ->assertSee('Using GITHUB_TOKEN fallback')
            ->assertDontSee('Saved');

        $this->get("/admin/packages/{$with->getRouteKey()}")
            ->assertOk()
            ->assertSee('Saved')
            // The secret itself is never rendered.
            ->assertDontSee('ghp_secret');
    }

    public function test_guests_cannot_reach_the_package_index(): void
    {
        auth()->logout();

        $this->get('/admin/packages')->assertRedirect('/admin/login');
    }

    public function test_an_admin_can_publish_a_public_page_from_the_edit_form(): void
    {
        Queue::fake();

        $package = Package::factory()->create(['name' => 'acme/widgets']);

        Livewire::test(EditPackage::class, ['record' => $package->getRouteKey()])
            ->fillForm([
                'page_enabled' => true,
                'page_downloads' => 'latest',
                'page_install' => true,
                'page_versions' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $package->refresh();

        $this->assertTrue($package->hasPage());
        $this->assertSame(PageDownloads::Latest, $package->page_downloads);
        $this->assertFalse($package->page_versions);

        // Enabling a page reads the repository's README straight away rather
        // than leaving the page blank until the next hourly sync.
        Queue::assertPushed(RefreshPackagePage::class);
    }

    public function test_publishing_a_page_is_recorded_in_the_audit_log(): void
    {
        Queue::fake();

        $package = Package::factory()->create(['name' => 'acme/widgets']);

        Livewire::test(EditPackage::class, ['record' => $package->getRouteKey()])
            ->fillForm(['page_enabled' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Package::class,
            'subject_id' => $package->id,
        ]);
    }
}
