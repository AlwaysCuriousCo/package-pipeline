<?php

namespace App\Filament\Resources\Packages\Actions;

use App\Models\Package;
use App\Services\PackageSynchronizer;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

class SyncPackageAction
{
    /**
     * A record action that pulls versions from GitHub for the package. Shared
     * by the table rows and the view/edit page headers.
     */
    public static function make(): Action
    {
        return Action::make('sync')
            ->label('Sync')
            ->icon(Heroicon::OutlinedArrowPath)
            ->action(function (Package $record): void {
                try {
                    app(PackageSynchronizer::class)->sync($record);

                    Notification::make()
                        ->success()
                        ->title("Synced {$record->refresh()->name}")
                        ->body("{$record->versions()->count()} versions are now being served.")
                        ->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Sync failed')
                        ->body($exception->getMessage())
                        ->send();
                }
            });
    }
}
