<?php

namespace Tests\Feature;

use App\Filament\Widgets\VersionReleaseHeatmap;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VersionReleaseHeatmapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_the_dashboard_only_registers_the_heatmap(): void
    {
        // Discovered widgets are keyed by file path, so compare on the values.
        $widgets = array_values(Filament::getPanel('admin')->getWidgets());

        $this->assertContains(VersionReleaseHeatmap::class, $widgets);
        $this->assertNotContains(AccountWidget::class, $widgets);
        $this->assertNotContains(FilamentInfoWidget::class, $widgets);
    }

    public function test_it_summarises_the_releases_of_the_current_year(): void
    {
        $year = CarbonImmutable::now()->year;

        $package = Package::factory()->create(['name' => 'acme/widgets']);
        PackageVersion::factory()->for($package)->create([
            'version' => 'v1.2.0',
            'released_at' => CarbonImmutable::create($year, 3, 4, 12),
        ]);
        PackageVersion::factory()->for($package)->create([
            'version' => 'v1.3.0',
            'released_at' => CarbonImmutable::create($year, 3, 4, 18),
        ]);

        Livewire::test(VersionReleaseHeatmap::class)
            ->assertSee("2 releases in {$year}")
            ->assertSee('1 package')
            ->assertSee('1 active day')
            ->assertSee('2 releases on '.CarbonImmutable::create($year, 3, 4)->format('M j, Y').': acme/widgets v1.2.0, acme/widgets v1.3.0');
    }

    public function test_it_reports_an_empty_year(): void
    {
        $year = CarbonImmutable::now()->year;

        PackageVersion::factory()->create([
            'released_at' => CarbonImmutable::create($year - 1, 6, 1),
        ]);

        Livewire::test(VersionReleaseHeatmap::class)
            ->assertSee("No releases recorded so far in {$year}.");
    }

    public function test_it_ignores_dev_versions_and_undated_references(): void
    {
        $year = CarbonImmutable::now()->year;

        PackageVersion::factory()->dev()->create([
            'released_at' => CarbonImmutable::create($year, 5, 1),
        ]);
        PackageVersion::factory()->create([
            'released_at' => null,
        ]);

        Livewire::test(VersionReleaseHeatmap::class)
            ->assertSee("No releases recorded so far in {$year}.");
    }
}
