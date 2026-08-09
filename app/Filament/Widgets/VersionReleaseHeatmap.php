<?php

namespace App\Filament\Widgets;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * A GitHub-style contribution graph of every tagged release across all tracked
 * packages, over the twelve months ending today.
 *
 * The window rolls rather than snapping to the calendar year: a graph that ran
 * to December would spend most of its width on months that have not happened
 * yet, whereas this one always ends in the current month with a full year of
 * history behind it.
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
     * The narrowest gap, in grid columns, between two month labels before the
     * later of the two crowds the earlier off the row.
     */
    protected const MONTH_LABEL_GAP = 3;

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $end = CarbonImmutable::now()->endOfDay();
        $start = $end->subYear()->addDay()->startOfDay();

        $versions = $this->releasedDuring($start, $end);
        $days = $this->groupByDay($versions);

        // The ramp is scaled against the busiest day so it always spans its full
        // range, however quiet or noisy the year turns out to be.
        $busiest = (int) $days->max('count');

        $weeks = $this->weeks($start, $end, $days, $busiest);

        return [
            'weeks' => $weeks,
            'months' => $this->months($start, $end, count($weeks)),
            'weekdays' => [1 => 'Mon', 3 => 'Wed', 5 => 'Fri'],
            'levels' => range(0, self::LEVELS),
            'summary' => $this->summary(
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
        $versions = PackageVersion::query()
            ->where('is_dev', false)
            ->whereBetween('released_at', [$start, $end]);

        $user = auth()->user();

        // A user with row-level scoping sees their packages' history, not the
        // whole registry's. visibleToUser() is a Package scope, so it runs as
        // a subquery over packages rather than inside whereHas(), which would
        // hand it an untyped relation builder instead.
        if ($user instanceof User) {
            $versions->whereIn(
                'package_id',
                Package::query()->visibleToUser($user)->select('packages.id'),
            );
        }

        return $versions
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
                        ($version->package->name ?? 'Unknown package').' '.$version->version
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
     * that fall either side of the window are held as nulls and rendered as
     * blanks, which is what leaves the current week ragged at the right.
     *
     * @param  Collection<string, array{count: int, releases: array<int, string>}>  $days
     * @return array<int, array<int, array<string, mixed>|null>>
     */
    protected function weeks(CarbonImmutable $start, CarbonImmutable $end, Collection $days, int $busiest): array
    {
        $cursor = $start->startOfWeek(CarbonInterface::SUNDAY);
        $gridEnd = $end->endOfWeek(CarbonInterface::SATURDAY);

        $weeks = [];

        while ($cursor <= $gridEnd) {
            $week = [];

            foreach (range(0, 6) as $offset) {
                $day = $cursor->addDays($offset);

                $week[] = $day->betweenIncluded($start, $end)
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
     * A rolling window opens and closes mid-month, so both ends need handling.
     * The opening month's 1st sits before the grid, so its label is pulled back
     * to column 1; each later month then evicts any label it would crowd, which
     * keeps the current month — the one the graph is anchored on — and drops a
     * sliver of a neighbour instead. Labels landing in the last few columns are
     * anchored to the right-hand edge, where they would otherwise overflow the
     * grid and drag a scrollbar along behind them.
     *
     * @return array<int, array{label: string, column: int, anchored: bool}>
     */
    protected function months(CarbonImmutable $start, CarbonImmutable $end, int $columns): array
    {
        $gridStart = $start->startOfWeek(CarbonInterface::SUNDAY);

        $months = [];
        $month = $start->startOfMonth();

        while ($month <= $end) {
            $column = max(1, intdiv((int) $gridStart->diffInDays($month), 7) + 1);

            while ($months !== [] && end($months)['column'] > $column - self::MONTH_LABEL_GAP) {
                array_pop($months);
            }

            $months[] = [
                'label' => $month->format('M'),
                'column' => $column,
                'anchored' => $column > $columns - self::MONTH_LABEL_GAP,
            ];

            $month = $month->addMonth();
        }

        return $months;
    }

    protected function summary(int $total, int $packages, int $activeDays, int $busiest): string
    {
        if ($total === 0) {
            return 'No releases recorded in the last 12 months.';
        }

        return implode(' · ', [
            'Last 12 months',
            $total.' '.($total === 1 ? 'release' : 'releases'),
            $packages.' '.($packages === 1 ? 'package' : 'packages'),
            $activeDays.' active '.($activeDays === 1 ? 'day' : 'days'),
            'busiest day '.$busiest,
        ]);
    }
}
