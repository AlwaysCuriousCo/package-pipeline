<?php

namespace App\Filament\Resources\Packages\Actions;

use App\Jobs\SyncPackageJob;
use App\Models\Package;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class SyncPackageAction
{
    /**
     * A record action that pulls versions from GitHub for the package. Shared
     * by the table rows and the view/edit page headers.
     *
     * The sync itself runs on the queue, so the outcome is not known by the
     * time this returns. Failures surface on the record instead, through the
     * sync_error column the table already renders.
     */
    public static function make(): Action
    {
        return Action::make('sync')
            ->label('Sync')
            ->icon(Heroicon::OutlinedArrowPath)
            // A package published by artifact upload has no repository to
            // pull from; offering a sync would only manufacture a failure.
            ->visible(fn (Package $record): bool => filled($record->repository))
            ->action(function (Package $record): void {
                // The job is unique per package until it starts, so a sync a
                // webhook already queued swallows this one — say so instead
                // of reporting work that will never run.
                if (! SyncPackageJob::dispatchUnlessPending($record)) {
                    Notification::make()
                        ->info()
                        ->title('Sync already queued')
                        ->body("{$record->name} already has a sync waiting to run.")
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Sync queued')
                    ->body("{$record->name} is being updated in the background.")
                    ->send();
            });
    }
}
