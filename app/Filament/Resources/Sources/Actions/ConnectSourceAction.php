<?php

namespace App\Filament\Resources\Sources\Actions;

use App\Models\Source;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

class ConnectSourceAction
{
    /**
     * Sends the admin to GitHub to install the app onto an organisation. The
     * handshake finishes in App\Http\Controllers\SourceConnectionController.
     */
    public static function make(): Action
    {
        return Action::make('connect')
            ->label(fn (Source $record): string => $record->isConnected() ? 'Reconnect' : 'Connect')
            ->icon(Heroicon::OutlinedLink)
            ->color(fn (Source $record): string => $record->isConnected() ? 'gray' : 'primary')
            ->url(fn (Source $record): ?string => ConnectGitHubAction::isAvailable()
                ? route('sources.connect', $record)
                : null)
            ->disabled(fn (): bool => ! ConnectGitHubAction::isAvailable())
            ->tooltip(fn (): ?string => ConnectGitHubAction::isAvailable()
                ? null
                : 'No GitHub App is registered for this instance — see docs/github-app.md.')
            // Only meaningful for app-based connections; a source holding its
            // own token has nothing to install.
            ->visible(fn (Source $record): bool => blank($record->token));
    }
}
