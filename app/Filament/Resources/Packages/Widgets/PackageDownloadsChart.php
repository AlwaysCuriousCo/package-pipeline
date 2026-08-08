<?php

namespace App\Filament\Resources\Packages\Widgets;

use App\Filament\Widgets\DownloadsChart;
use App\Models\Download;
use App\Models\Package;
use Illuminate\Database\Eloquent\Builder;

/**
 * The viewed package's own download history, shown under the package
 * details: the dashboard chart narrowed to one package and stretched to a
 * quarter's worth of days.
 */
class PackageDownloadsChart extends DownloadsChart
{
    protected ?string $description = 'Dist downloads per day, last 90 days.';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '160px';

    protected int $days = 90;

    /**
     * Filled by the view page via InteractsWithRecord::getWidgetData().
     */
    public ?Package $record = null;

    /**
     * No visibility scope on top of the record's: whoever may open the page
     * may see its package's downloads.
     *
     * @return Builder<Download>
     */
    protected function downloads(): Builder
    {
        return Download::query()->where('package_id', $this->record?->id);
    }
}
