<?php

namespace App\Filament\Widgets;

use App\Models\PackageVersion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * A GitHub-style contribution graph of every tagged release across all tracked
 * packages, for the calendar year in progress.
 */
class VersionReleaseHeatmap extends Widget
{
    protected string $view = 'filament.widgets.version-release-heatmap';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -2;

    /**
     * How many releases a day's tooltip names before it summarises the rest.
     */
    protected const TOOLTIP_RELEASES = 4;

    /**
     * How many shades the colour ramp has above "nothing happened".
     */
    protected const LEVELS = 4;

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $year = CarbonImmutable::now()->year;
        $start = CarbonImmutable::create($year, 1, 1)->startOfDay();

        $versions = $this->releasedDuring($start, $start->endOfYear());
        $days = $this->groupByDay($versions);

        // The ramp is scaled against the busiest day so it always spans its full
        // range, however quiet or noisy the year turns out to be.
        $busiest = (int) $days->max('count');

        return [
            'year' => $year,
            'weeks' => $this->weeks($start, $days, $busiest),
            'months' => $this->months($start),
            'weekdays' => [1 => 'Mon', 3 => 'Wed', 5 => 'Fri'],
            'levels' => range(0, self::LEVELS),
            'summary' => $this->summary(
                year: $year,
                total: $versions->count(),
                packages: $versions->pluck('package_id')->unique()->count(),
                activeDays: $days->count(),
                busiest: $busiest,
            ),
        ];
    }

    /**
     * The tagged releases that landed inside the given window.
     *
     * Dev versions are left out: their `released_at` tracks the tip of a branch
     * and is rewritten on every sync, so they would repaint the same cell
     * forever rather than mark a release that actually happened. Versions with
     * no release date are dropped by the range comparison, which is what we
     * want — an undated ref is a gap in the history, not a day's activity.
     *
     * @return Collection<int, PackageVersion>
     */
    protected function releasedDuring(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return PackageVersion::query()
            ->where('is_dev', false)
            ->whereBetween('released_at', [$start, $end])
            ->with('package:id,name')
            ->get(['id', 'package_id', 'version', 'released_at']);
    }

    /**
     * Bucket the releases by calendar day, keyed by `Y-m-d`.
     *
     * Grouped in PHP rather than SQL because date truncation has no portable
     * spelling across SQLite, MySQL and Postgres, and a single year of releases
     * is a small enough set to sort in memory.
     *
     * @param  Collection<int, PackageVersion>  $versions
     * @return Collection<string, array{count: int, releases: array<int, string>}>
     */
    protected function groupByDay(Collection $versions): Collection
    {
        return $versions
            ->groupBy(fn (PackageVersion $version): string => $version->released_at->toDateString())
            ->map(fn (Collection $released): array => [
                'count' => $released->count(),
                'releases' => $released
                    ->map(fn (PackageVersion $version): string => trim(
                        ($version->package?->name ?? 'Unknown package').' '.$version->version
                    ))
                    ->sort()
                    ->values()
                    ->all(),
            ]);
    }

    /**
     * The grid, as columns of seven days running Sunday to Saturday.
     *
     * Columns are padded out to whole weeks so every row lines up; the days
     * that spill into the neighbouring years are held as nulls and rendered as
     * blanks.
     *
     * @param  Collection<string, array{count: int, releases: array<int, string>}>  $days
     * @return array<int, array<int, array<string, mixed>|null>>
     */
    protected function weeks(CarbonImmutable $start, Collection $days, int $busiest): array
    {
        $cursor = $start->startOfWeek(CarbonInterface::SUNDAY);
        $end = $start->endOfYear()->endOfWeek(CarbonInterface::SATURDAY);

        $weeks = [];

        while ($cursor <= $end) {
            $week = [];

            foreach (range(0, 6) as $offset) {
                $day = $cursor->addDays($offset);

                $week[] = $day->year === $start->year
                    ? $this->cell($day, $days->get($day->toDateString()), $busiest)
                    : null;
            }

            $weeks[] = $week;
            $cursor = $cursor->addWeek();
        }

        return $weeks;
    }

    /**
     * @param  array{count: int, releases: array<int, string>}|null  $day
     * @return array<string, mixed>
     */
    protected function cell(CarbonImmutable $date, ?array $day, int $busiest): array
    {
        $count = $day['count'] ?? 0;

        return [
            'count' => $count,
            // A day with any activity is never level 0, so a single release in a
            // busy year still shows up rather than fading into the empty grid.
            'level' => $count === 0 ? 0 : max(1, (int) ceil(self::LEVELS * $count / $busiest)),
            'label' => $this->label($date, $count, $day['releases'] ?? []),
        ];
    }

    /**
     * @param  array<int, string>  $releases
     */
    protected function label(CarbonImmutable $date, int $count, array $releases): string
    {
        $on = $date->format('M j, Y');

        if ($count === 0) {
            return "No releases on {$on}";
        }

        $named = array_slice($releases, 0, self::TOOLTIP_RELEASES);
        $remaining = $count - count($named);

        if ($remaining > 0) {
            $named[] = "and {$remaining} more";
        }

        $noun = $count === 1 ? 'release' : 'releases';

        return "{$count} {$noun} on {$on}: ".implode(', ', $named);
    }

    /**
     * Month names, each pinned to the grid column holding the 1st of the month.
     *
     * @return array<int, array{label: string, column: int}>
     */
    protected function months(CarbonImmutable $start): array
    {
        $gridStart = $start->startOfWeek(CarbonInterface::SUNDAY);

        return array_map(fn (int $offset): array => [
            'label' => $start->addMonths($offset)->format('M'),
            'column' => intdiv((int) $gridStart->diffInDays($start->addMonths($offset)), 7) + 1,
        ], range(0, 11));
    }

    protected function summary(int $year, int $total, int $packages, int $activeDays, int $busiest): string
    {
        if ($total === 0) {
            return "No releases recorded so far in {$year}.";
        }

        return implode(' · ', [
            $total.' '.($total === 1 ? 'release' : 'releases').' in '.$year,
            $packages.' '.($packages === 1 ? 'package' : 'packages'),
            $activeDays.' active '.($activeDays === 1 ? 'day' : 'days'),
            'busiest day '.$busiest,
        ]);
    }
}
