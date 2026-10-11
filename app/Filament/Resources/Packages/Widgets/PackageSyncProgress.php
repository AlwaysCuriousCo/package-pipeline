<?php

namespace App\Filament\Resources\Packages\Widgets;

use App\Models\Package;
use Filament\Widgets\Widget;

/**
 * Live progress of the sync batch importing this package's versions, shown at
 * the top of the package's view page.
 *
 * The view polls, so the widget appears on its own a few seconds after a sync
 * is queued and leaves once every import has run. A batch that finished with
 * failures is not "in progress": what it left behind is on the package's
 * sync_error, which the page below already shows.
 */
class PackageSyncProgress extends Widget
{
    protected string $view = 'filament.resources.packages.widgets.package-sync-progress';

    protected int|string|array $columnSpan = 'full';

    /**
     * Filled by the view page via InteractsWithRecord::getWidgetData().
     */
    public ?Package $record = null;

    /**
     * Whether the package had versions at the last render, so the poll can
     * tell when its first ones land.
     */
    public ?bool $hadVersions = null;

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        // Actions that need a version — Test installation — are the page's,
        // not this widget's, and the page does not poll. Telling it when the
        // first versions land shows them without a reload, however quick the
        // sync was between two polls.
        $hasVersions = $this->record?->versions()->exists() ?? false;

        if ($this->hadVersions === false && $hasVersions) {
            $this->dispatch('package-synced');
        }

        $this->hadVersions = $hasVersions;

        $batch = $this->record?->syncBatch();

        if ($batch === null || ! $this->record->syncRunning()) {
            return ['running' => false];
        }

        // The batch's first job is the discovery that fans the imports out, so
        // the import counts leave it off — "1 of 5 jobs" is not what anyone
        // watching a four-version repository wants to read.
        return [
            'running' => true,
            'discovering' => $batch->totalJobs <= 1,
            'imported' => max(0, $batch->processedJobs() - 1),
            'total' => max(0, $batch->totalJobs - 1),
            'failed' => $batch->failedJobs,
            'progress' => $batch->progress(),
        ];
    }
}
