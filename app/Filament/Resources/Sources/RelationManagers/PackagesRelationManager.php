<?php

namespace App\Filament\Resources\Sources\RelationManagers;

use App\Filament\Resources\Packages\PackageResource;
use App\Models\Package;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The packages authenticating through this source — the other half of the
 * bridge, so it is obvious what breaks if the source is disconnected.
 */
class PackagesRelationManager extends RelationManager
{
    protected static string $relationship = 'packages';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->recordUrl(fn (Package $record): string => PackageResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('repository')
                    ->label('Repository')
                    ->searchable()
                    ->limit(50),
                TextColumn::make('latest_version')
                    ->label('Latest version')
                    ->badge()
                    ->placeholder('Unreleased'),
                TextColumn::make('last_synced_at')
                    ->label('Last synced')
                    ->since()
                    ->sortable()
                    ->placeholder('Never')
                    ->color(fn (Package $record): ?string => $record->sync_error ? 'danger' : null)
                    ->tooltip(fn (Package $record): ?string => $record->sync_error),
            ]);
    }
}
