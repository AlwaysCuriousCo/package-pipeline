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
            ->action(function (Package $record): void {
                SyncPackageJob::dispatch($record);

                Notification::make()
                    ->success()
                    ->title('Sync queued')
                    ->body("{$record->name} is being updated in the background.")
                    ->send();
            });
    }
}
