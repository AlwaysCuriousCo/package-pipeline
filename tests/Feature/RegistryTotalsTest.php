<?php

namespace Tests\Feature;

use App\Filament\Widgets\RegistryTotals;
use App\Models\Download;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Repository;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Tests\TestCase;

class RegistryTotalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_registers_the_widget(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->assertContains(
            RegistryTotals::class,
            array_values(Filament::getPanel('admin')->getWidgets()),
        );
    }

    public function test_it_counts_the_whole_registry_for_an_unscoped_user(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $repository = Repository::factory()->create();
        $package = Package::factory()->create(['repository_id' => $repository->id, 'total_downloads' => 1]);
        PackageVersion::factory()->count(2)->for($package)->create();

        // The seeded default repository stands beside the factory-made one.
        $this->assertSame([
            'Repositories' => '2',
            'Packages' => '1',
            'Versions' => '2',
            'Downloads' => '1',
        ], $this->totals()->all());

        Livewire::test(RegistryTotals::class)
            ->assertSee('Registry totals')
            ->assertSee('Repositories')
            ->assertSee('Downloads');
    }

    public function test_totals_are_narrowed_to_what_the_user_may_see(): void
    {
        $this->actingAs($user = User::factory()->create());

        $public = Repository::factory()->public()->create();
        $granted = Repository::factory()->create();
        $hidden = Repository::factory()->create();

        $user->repositories()->attach($granted);

        $visible = Package::factory()->create(['repository_id' => $public->id, 'total_downloads' => 1]);
        Package::factory()->create(['repository_id' => $granted->id]);
        $unseen = Package::factory()->create(['repository_id' => $hidden->id, 'total_downloads' => 7]);

        // A single granted package also pulls its repository into view.
        $adopted = Package::factory()->create(['repository_id' => Repository::factory()->create()->id]);
        $user->packages()->attach($adopted);

        PackageVersion::factory()->for($visible)->create();
        PackageVersion::factory()->count(3)->for($unseen)->create();

        // Visible: the seeded default (public), $public, $granted, and the
        // adopted package's home — but never $hidden.
        $this->assertSame([
            'Repositories' => '4',
            'Packages' => '3',
            'Versions' => '1',
            'Downloads' => '1',
        ], $this->totals()->all());
    }

    public function test_downloads_are_summed_from_the_denormalized_counters(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Package::factory()->create(['total_downloads' => 12]);
        Package::factory()->create(['total_downloads' => 30]);
        Package::factory()->create();

        // Rows in the downloads table are not what the card reads; the
        // listener's counters are, and downloads:recalculate is what puts the
        // two back in step when they drift.
        Download::create(['package_id' => Package::query()->first()->id, 'version' => '1.0.0']);

        $this->assertSame('42', $this->totals()['Downloads']);
    }

    /**
     * The quad's values keyed by label, read off the widget's view data.
     *
     * @return Collection<string, string>
     */
    private function totals(): Collection
    {
        $widget = new RegistryTotals;

        $stats = (fn (): array => $this->getViewData()['stats'])->call($widget);

        return collect($stats)->pluck('value', 'label');
    }
}
