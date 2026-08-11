<?php

namespace App\Filament\Resources\Sources\RelationManagers;

use App\Filament\Resources\Packages\Actions\SyncPackageAction;
use App\Filament\Resources\Packages\PackageResource;
use App\Models\Package;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
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
            ->headerActions([
                Action::make('createPackage')
                    ->label('New package')
                    ->icon(Heroicon::OutlinedPlus)
                    // Creation is a wizard of its own, so this links out to it
                    // rather than opening a modal — carrying the source along
                    // so the new package authenticates through it from the start.
                    ->url(fn (): string => PackageResource::getUrl('create', ['source' => $this->getOwnerRecord()->getKey()]))
                    ->visible(fn (): bool => PackageResource::canCreate()),
            ])
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
            ])
            // Reconnecting a source is exactly when its packages need
            // re-syncing, and this page is where an admin already is. The same
            // action the main table carries, so there is one queueing rule.
            ->recordActions([
                SyncPackageAction::make(),
            ]);
    }
}
