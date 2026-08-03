<?php

namespace Tests\Feature;

use App\Filament\Resources\Packages\Pages\CreatePackage;
use App\Filament\Resources\Packages\Pages\EditPackage;
use App\Filament\Resources\Packages\Pages\ListPackages;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PackageResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_the_package_index_lists_records(): void
    {
        $packages = Package::factory()->count(3)->create();

        Livewire::test(ListPackages::class)
            ->assertCanSeeTableRecords($packages);
    }

    public function test_a_package_can_be_created(): void
    {
        Livewire::test(CreatePackage::class)
            ->fillForm([
                'name' => 'acme/widgets',
                'repository' => 'https://github.com/acme/widgets',
                'latest_version' => 'v2.1.0',
                'type' => 'library',
                'description' => 'Widgets for Acme.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('packages', [
            'name' => 'acme/widgets',
            'repository' => 'https://github.com/acme/widgets',
            'latest_version' => 'v2.1.0',
            'type' => 'library',
        ]);
    }

    public function test_a_package_can_be_created_without_a_release(): void
    {
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

    public function test_guests_cannot_reach_the_package_index(): void
    {
        auth()->logout();

        $this->get('/admin/packages')->assertRedirect('/admin/login');
    }
}
