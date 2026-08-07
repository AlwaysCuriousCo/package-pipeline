<?php

namespace App\Filament\Resources\Sources\Actions;

use App\Models\Source;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class DisconnectSourceAction
{
    /**
     * Drops the stored credentials without deleting the source, so its
     * packages keep their link and start working again once it is reconnected.
     */
    public static function make(): Action
    {
        return Action::make('disconnect')
            ->label('Disconnect')
            ->icon(Heroicon::OutlinedLinkSlash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Disconnect this source?')
            ->modalDescription(fn (Source $record): string => "Syncing {$record->packages()->count()} package(s) will fail until the source is connected again. Uninstall the app on GitHub separately if you want to revoke access there too.")
            ->action(function (Source $record): void {
                $record->disconnect();

                Notification::make()
                    ->success()
                    ->title("Disconnected {$record->name}")
                    ->send();
            })
            ->visible(fn (Source $record): bool => $record->isConnected());
    }
}
