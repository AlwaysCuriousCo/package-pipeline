<?php

namespace App\Filament\Resources\Sources\Schemas;

use App\Models\Source;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class SourceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name'),
                TextEntry::make('provider')
                    ->badge(),
                TextEntry::make('account')
                    ->label('Organisation or user')
                    ->url(fn (Source $record): ?string => $record->account
                        ? "https://{$record->provider->host()}/{$record->account}"
                        : null)
                    ->openUrlInNewTab()
                    ->color('primary')
                    ->placeholder('Not connected yet'),
                TextEntry::make('account_type')
                    ->label('Account type')
                    ->placeholder('-'),
                TextEntry::make('connected_at')
                    ->label('Connected')
                    ->since()
                    ->placeholder('Never'),
                TextEntry::make('installation_id')
                    ->label('Authentication')
                    ->badge()
                    ->color(fn (Source $record): string => $record->usesInstallation() ? 'success' : 'warning')
                    // Token-based sources have no installation id, so the
                    // state is filled in from the record rather than the
                    // column to keep the entry from falling back to a dash.
                    ->state(fn (Source $record): string => match (true) {
                        $record->usesInstallation() => "GitHub App installation #{$record->installation_id}",
                        filled($record->token) => 'Access token',
                        default => 'None',
                    }),
                TextEntry::make('metadata.repository_selection')
                    ->label('Repository access')
                    ->formatStateUsing(fn (string $state): string => $state === 'all'
                        ? 'All repositories in the account'
                        : 'Only the selected repositories')
                    ->placeholder('-'),
                TextEntry::make('metadata.repository_count')
                    ->label('Repositories reachable')
                    ->placeholder('Unknown — run "Test connection"'),
                TextEntry::make('base_url')
                    ->label('API base URL')
                    ->placeholder('https://api.github.com'),
                TextEntry::make('connection_error')
                    ->label('Connection error')
                    ->color('danger')
                    ->placeholder('None')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
