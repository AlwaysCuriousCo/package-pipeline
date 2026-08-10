<?php

namespace Tests\Feature;

use App\Filament\Widgets\VersionReleaseHeatmap;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Repository;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class VersionReleaseHeatmapTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The graph is anchored on "today", so the clock is pinned to keep the
     * window — and the dates that fall either side of it — deterministic.
     */
    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->superAdmin()->create());

        $this->today = CarbonImmutable::create(2026, 8, 7, 12);
        $this->travelTo($this->today);
    }

    public function test_the_dashboard_only_registers_the_heatmap(): void
    {
        // Discovered widgets are keyed by file path, so compare on the values.
        $widgets = array_values(Filament::getPanel('admin')->getWidgets());

        $this->assertContains(VersionReleaseHeatmap::class, $widgets);
        $this->assertNotContains(AccountWidget::class, $widgets);
        $this->assertNotContains(FilamentInfoWidget::class, $widgets);
    }

    public function test_it_summarises_the_releases_of_the_last_twelve_months(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);
        PackageVersion::factory()->for($package)->create([
            'version' => 'v1.2.0',
            'released_at' => $this->today->subMonths(5)->setTime(9, 0),
        ]);
        PackageVersion::factory()->for($package)->create([
            'version' => 'v1.3.0',
            'released_at' => $this->today->subMonths(5)->setTime(17, 0),
        ]);

        Livewire::test(VersionReleaseHeatmap::class)
            ->assertSee('Last 12 months · 2 releases · 1 package · 1 active day · busiest day 2')
            ->assertSee('2 releases on '.$this->today->subMonths(5)->format('M j, Y').': acme/widgets v1.2.0, acme/widgets v1.3.0');
    }

    public function test_it_spans_the_twelve_months_ending_today(): void
    {
        PackageVersion::factory()->create([
            'released_at' => $this->today->subMonths(11),
        ]);

        // A day older than the window, and a day the window has not reached.
        PackageVersion::factory()->create([
            'released_at' => $this->today->subMonths(13),
        ]);
        PackageVersion::factory()->create([
            'released_at' => $this->today->addWeek(),
        ]);

        Livewire::test(VersionReleaseHeatmap::class)
            ->assertSee('Last 12 months · 1 release · 1 package · 1 active day · busiest day 1');
    }

    public function test_it_runs_the_month_labels_up_to_the_current_month(): void
    {
        $data = $this->viewData();

        // Thirteen labels, because a rolling window clips a month at each end:
        // the graph opens part way through August 2025 and closes part way
        // through August 2026.
        $this->assertSame(
            ['Aug', 'Sep', 'Oct', 'Nov', 'Dec', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug'],
            array_column($data['months'], 'label')
        );

        $last = end($data['months']);

        $this->assertGreaterThan(count($data['weeks']) - 3, $last['column']);
        $this->assertTrue($last['anchored']);
    }

    public function test_it_ends_on_the_week_containing_today(): void
    {
        $data = $this->viewData();

        // Today is a Friday, so the final column carries a Saturday it has not
        // reached yet — the ragged edge a rolling window is supposed to have.
        $this->assertSame(53, count($data['weeks']));
        $this->assertNotNull(end($data['weeks'])[5]);
        $this->assertNull(end($data['weeks'])[6]);
    }

    public function test_it_reports_an_empty_window(): void
    {
        PackageVersion::factory()->create([
            'released_at' => $this->today->subYears(2),
        ]);

        Livewire::test(VersionReleaseHeatmap::class)
            ->assertSee('No releases recorded in the last 12 months.');
    }

    public function test_it_ignores_dev_versions_and_undated_references(): void
    {
        PackageVersion::factory()->dev()->create([
            'released_at' => $this->today->subMonth(),
        ]);
        PackageVersion::factory()->create([
            'released_at' => null,
        ]);

        Livewire::test(VersionReleaseHeatmap::class)
            ->assertSee('No releases recorded in the last 12 months.');
    }

    /**
     * The grid needs the releases themselves and not just counts — the tooltip
     * names them — so the year is read row by row rather than grouped in SQL.
     * That makes the read worth doing once for everybody looking at the
     * dashboard, rather than once per look.
     */
    public function test_it_reads_the_year_once_for_everyone_looking(): void
    {
        PackageVersion::factory()->create(['released_at' => $this->today->subMonth()]);

        $this->assertSame(1, $this->versionQueriesWhileRendering());
        $this->assertSame(0, $this->versionQueriesWhileRendering());
    }

    /**
     * The window is cut by the viewer's grants, so one operator's year is not
     * an answer to another operator's question.
     */
    public function test_one_viewers_year_is_not_served_to_another(): void
    {
        $package = Package::factory()->create(['repository_id' => Repository::factory()->create()->id]);

        PackageVersion::factory()->for($package)->create(['released_at' => $this->today->subMonth()]);

        $this->assertStringContainsString('1 release', $this->viewData()['summary']);

        $this->actingAs(User::factory()->create());

        $this->assertSame('No releases recorded in the last 12 months.', $this->viewData()['summary']);
    }

    /**
     * How many queries one render puts against the version table.
     */
    private function versionQueriesWhileRendering(): int
    {
        $queries = 0;

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'package_versions')) {
                $queries++;
            }
        });

        $this->viewData();

        // The listener cannot be removed, so each call measures itself against
        // its own counter and the earlier ones keep counting into theirs.
        return $queries;
    }

    /**
     * @return array<string, mixed>
     */
    private function viewData(): array
    {
        $widget = new VersionReleaseHeatmap;

        return (fn (): array => $this->getViewData())->call($widget);
    }
}
