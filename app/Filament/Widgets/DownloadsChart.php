<?php

namespace App\Filament\Widgets;

use App\Models\Download;
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
        return Download::query()->when(
            auth()->user(),
            fn ($query, $user) => $query->whereHas(
                'package',
                fn ($packages) => $packages->visibleToUser($user),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $end = CarbonImmutable::now()->endOfDay();
        $start = $end->subDays($this->days - 1)->startOfDay();

        // Grouped in PHP rather than SQL for the same reason as the release
        // heatmap: date truncation has no portable spelling, and a few months
        // of timestamps is a small set.
        $byDay = $this->downloads()
            ->where('created_at', '>=', $start)
            ->pluck('created_at')
            ->countBy(fn (CarbonImmutable $created): string => $created->toDateString());

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
