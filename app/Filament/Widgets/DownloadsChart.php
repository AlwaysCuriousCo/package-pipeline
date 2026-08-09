<?php

namespace App\Filament\Widgets;

use App\Models\Download;
use App\Models\Package;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Downloads per day over the last 30 days, scoped like everything else on
 * the dashboard to the packages the signed-in user may see.
 *
 * The window and the query are seams: the package page's chart is this one
 * with both dials turned.
 */
class DownloadsChart extends ChartWidget
{
    protected ?string $heading = 'Downloads';

    protected ?string $description = 'Dist downloads per day, last 30 days.';

    protected static ?int $sort = -3;

    /**
     * The right half of the quad's row.
     */
    protected int|string|array $columnSpan = 1;

    protected ?string $maxHeight = '180px';

    /**
     * How many days of history the chart spans.
     */
    protected int $days = 30;

    /**
     * A day's bar cannot visibly move inside five seconds, which is what
     * ChartWidget polls at out of the box — and each poll is an aggregate
     * over every download served in the window. Once a minute is as often as
     * a daily bucket has anything new to say.
     */
    protected ?string $pollingInterval = '60s';

    /**
     * How long one computed set of buckets stands for, sized to the polling
     * interval so extra tabs and extra viewers with the same scope are free.
     */
    private const CACHE_SECONDS = 60;

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'y' => [
                    // Counts can't go negative, and suggestedMax keeps the
                    // axis from collapsing to a flat band when every day is
                    // zero (Chart.js otherwise pads the scale to [-1, 1]).
                    'min' => 0,
                    'suggestedMax' => 5,
                    'ticks' => ['precision' => 0],
                ],
            ],
        ];
    }

    /**
     * The downloads this chart counts: every package the signed-in user may
     * see.
     *
     * @return Builder<Download>
     */
    protected function downloads(): Builder
    {
        $downloads = Download::query();
        $user = auth()->user();

        // visibleToUser() is a Package scope, so it runs as a subquery over
        // packages rather than inside whereHas(), which would hand it an
        // untyped relation builder instead.
        if ($user instanceof User) {
            $downloads->whereIn(
                'package_id',
                Package::query()->visibleToUser($user)->select('packages.id'),
            );
        }

        return $downloads;
    }

    /**
     * What distinguishes one viewer's version of this chart from another's.
     *
     * The dashboard chart is cut by the signed-in user's grants; the package
     * page's is cut by the package. Both are cached, so both have to say
     * which slice they are.
     */
    protected function scopeKey(): string
    {
        return 'user:'.(auth()->user()?->getAuthIdentifier() ?? 'guest');
    }

    /**
     * Downloads per calendar day inside the window, keyed `Y-m-d`, with the
     * empty days simply absent.
     *
     * Counted by the database rather than hydrated and counted here. The
     * previous shape plucked every `created_at` in the window into a PHP
     * collection to countBy() it — a registry serving a hundred thousand
     * downloads a month therefore built a hundred-thousand-element collection
     * on every poll, to produce thirty integers.
     *
     * Cached alongside that, because the aggregate is still a range scan over
     * everything served in the window: a minute's worth of tabs and viewers
     * sharing a scope now costs one of them.
     *
     * @return array<string, int>
     */
    protected function countsByDay(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $key = implode(':', ['widget:downloads-chart', static::class, $this->days, $start->toDateString(), $this->scopeKey()]);

        return cache()->remember($key, self::CACHE_SECONDS, function () use ($start, $end): array {
            $day = $this->dayExpression();

            $rows = $this->downloads()
                // Bounded at both ends so the index range scan is closed:
                // the loop below reads nothing outside the window anyway.
                ->whereBetween('created_at', [$start, $end])
                ->toBase()
                ->selectRaw("{$day} as day, count(*) as downloads")
                ->groupByRaw($day)
                ->get();

            $counts = [];

            foreach ($rows as $row) {
                $counts[(string) $row->day] = (int) $row->downloads;
            }

            return $counts;
        });
    }

    /**
     * The SQL that truncates `created_at` to a calendar day on the connection
     * in use.
     *
     * The one dialect-specific expression in this app, kept to a single line
     * because there is no portable spelling of date truncation and the
     * alternative — pulling the rows back to group them in PHP — is the cost
     * this method exists to avoid. `CAST(x AS DATE)` is ANSI and correct on
     * MySQL, MariaDB, Postgres and SQL Server; SQLite has no date type, so
     * the cast falls through to its numeric affinity and yields the year as
     * an integer. `date()` is the only spelling that truncates there.
     *
     * Both forms render back as `Y-m-d`, which is what the buckets are keyed
     * by, and both read the stored value as written — the same wall clock
     * Carbon parsed it in, so no day moves.
     */
    private function dayExpression(): string
    {
        return $this->downloads()->getModel()->getConnection()->getDriverName() === 'sqlite'
            ? 'date(created_at)'
            : 'cast(created_at as date)';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $end = CarbonImmutable::now()->endOfDay();
        $start = $end->subDays($this->days - 1)->startOfDay();

        $byDay = $this->countsByDay($start, $end);

        $labels = [];
        $counts = [];

        for ($day = $start; $day <= $end; $day = $day->addDay()) {
            $labels[] = $day->format('M j');
            $counts[] = $byDay[$day->toDateString()] ?? 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Downloads',
                    'data' => $counts,
                    'fill' => 'start',
                ],
            ],
            'labels' => $labels,
        ];
    }
}
