<?php

namespace App\Filament\Widgets;

use App\Models\Download;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/**
 * Downloads per day over the last 90 days, scoped like everything else on
 * the dashboard to the packages the signed-in user may see.
 */
class DownloadsChart extends ChartWidget
{
    protected ?string $heading = 'Downloads';

    protected ?string $description = 'Dist downloads per day, last 90 days.';

    protected static ?int $sort = -1;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '160px';

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
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $end = CarbonImmutable::now()->endOfDay();
        $start = $end->subDays(89)->startOfDay();

        // Grouped in PHP rather than SQL for the same reason as the release
        // heatmap: date truncation has no portable spelling, and 90 days of
        // timestamps is a small set.
        $byDay = Download::query()
            ->where('created_at', '>=', $start)
            ->when(
                auth()->user(),
                fn ($query, $user) => $query->whereHas(
                    'package',
                    fn ($packages) => $packages->visibleToUser($user),
                ),
            )
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
