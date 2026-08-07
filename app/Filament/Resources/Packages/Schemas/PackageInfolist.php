<?php

namespace App\Filament\Resources\Packages\Schemas;

use App\Filament\Resources\Sources\SourceResource;
use App\Models\Package;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class PackageInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name'),
                TextEntry::make('repository')
                    ->label('Repository URL')
                    ->url(fn (Package $record): string => $record->repository)
                    ->openUrlInNewTab()
                    ->color('primary'),
                TextEntry::make('latest_version')
                    ->label('Latest version')
                    ->badge()
                    ->placeholder('Unreleased'),
                TextEntry::make('type')
                    ->badge()
                    ->placeholder('-'),
                TextEntry::make('source.name')
                    ->label('Source')
                    ->badge()
                    ->color(fn (Package $record): string => $record->source?->isConnected() ? 'success' : 'warning')
                    ->url(fn (Package $record): ?string => $record->source
                        ? SourceResource::getUrl('view', ['record' => $record->source])
                        : null)
                    ->placeholder('No source — using this package\'s own credentials'),
                TextEntry::make('token')
                    ->label('GitHub token')
                    // Never render the secret itself.
                    ->formatStateUsing(fn (): string => 'Saved')
                    ->badge()
                    ->color('success')
                    ->placeholder(fn (Package $record): string => $record->source
                        ? 'Not needed — authenticating through the source'
                        : 'Using GITHUB_TOKEN fallback'),
                TextEntry::make('last_synced_at')
                    ->label('Last synced')
                    ->since()
                    ->placeholder('Never'),
                TextEntry::make('sync_error')
                    ->label('Sync error')
                    ->color('danger')
                    ->placeholder('None')
                    ->columnSpanFull(),
                TextEntry::make('description')
                    ->placeholder('-')
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
