<?php

namespace Tests\Feature;

use App\Filament\Resources\Packages\Pages\ViewPackage;
use App\Filament\Resources\Packages\Widgets\PackageSyncProgress;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Attributes\On;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A package page opened while its first sync runs shows Test installation as
 * soon as the versions land, rather than after a reload nobody knew to do.
 */
class PackageSyncedTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->superAdmin()->create());

        $this->package = Package::factory()->create(['name' => 'acme/widgets']);
    }

    public function test_the_sync_widget_says_when_the_first_versions_land(): void
    {
        $widget = Livewire::test(PackageSyncProgress::class, ['record' => $this->package]);

        $widget->call('$refresh')->assertNotDispatched('package-synced');

        PackageVersion::factory()->for($this->package)->create();

        $widget->call('$refresh')->assertDispatched('package-synced');
    }

    public function test_a_package_that_already_had_versions_is_not_announced(): void
    {
        PackageVersion::factory()->for($this->package)->create();

        Livewire::test(PackageSyncProgress::class, ['record' => $this->package])
            ->assertNotDispatched('package-synced')
            ->call('$refresh')
            ->assertNotDispatched('package-synced');
    }

    public function test_the_package_page_shows_test_installation_once_told_the_versions_landed(): void
    {
        // The browser only sends the event to a component listening for it.
        $listener = new ReflectionMethod(ViewPackage::class, 'refreshAfterSync');
        $this->assertSame(['package-synced'], array_map(
            fn ($attribute): mixed => $attribute->getArguments()[0],
            $listener->getAttributes(On::class),
        ));

        $page = Livewire::test(ViewPackage::class, ['record' => $this->package->getRouteKey()])
            ->assertActionHidden('testInstallation');

        PackageVersion::factory()->for($this->package)->create();

        $page->dispatch('package-synced')->assertActionVisible('testInstallation');
    }
}
