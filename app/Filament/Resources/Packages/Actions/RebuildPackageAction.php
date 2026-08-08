<?php

namespace App\Filament\Resources\Packages\Actions;

use App\Models\Package;
use App\Services\RebuildPackage;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class RebuildPackageAction
{
    /**
     * Queue a full rebuild of the package's versions from its source.
     *
     * Distinct from Sync on purpose: a sync skips whatever looks already
     * stored, a rebuild trusts none of it.
     */
    public static function make(): Action
    {
        return Action::make('rebuild')
            ->label('Rebuild')
            ->icon(Heroicon::OutlinedWrenchScrewdriver)
            ->color('warning')
            // A package published by artifact upload has no source to rebuild
            // from.
            ->visible(fn (Package $record): bool => filled($record->repository))
            ->requiresConfirmation()
            ->modalHeading('Rebuild this package')
            ->modalDescription('Every version is re-imported from the source: metadata re-read, archives re-downloaded, versions gone upstream pruned. The package keeps serving while the rebuild runs, and running it twice is safe.')
            ->modalSubmitActionLabel('Rebuild')
            ->action(function (Package $record): void {
                if (! app(RebuildPackage::class)->queue($record)) {
                    Notification::make()
                        ->info()
                        ->title('Sync already queued')
                        ->body("{$record->name} already has a sync waiting to run; the rebuild would only collide with it.")
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Rebuild queued')
                    ->body("{$record->name} is re-importing every version in the background.")
                    ->send();
            });
    }
}
