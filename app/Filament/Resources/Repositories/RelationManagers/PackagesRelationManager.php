<?php

namespace App\Filament\Resources\Repositories\RelationManagers;

use App\Filament\Resources\Packages\PackageResource;
use App\Models\Package;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * What this repository actually serves.
 *
 * The form above is all configuration — paths, reservations, upstreams — and
 * none of it says what a consumer pointed at the mount would find. Read-only on
 * purpose: a package belongs to a repository from the moment it is created and
 * moving one would change the URL Composer resolves it at, so this lists and
 * links out rather than editing in place.
 */
class PackagesRelationManager extends RelationManager
{
    protected static string $relationship = 'packages';

    protected static ?string $title = 'Packages';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->emptyStateHeading('No packages served here yet')
            ->emptyStateDescription('Packages added to this repository are served from its mount path.')
            ->recordUrl(fn (Package $record): string => PackageResource::getUrl('view', ['record' => $record]))
            ->headerActions([
                Action::make('createPackage')
                    ->label('New package')
                    ->icon(Heroicon::OutlinedPlus)
                    // Creation is a wizard of its own, so this links out to it
                    // rather than opening a modal.
                    ->url(fn (): string => PackageResource::getUrl('create'))
                    ->visible(fn (): bool => PackageResource::canCreate()),
            ])
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('latest_version')
                    ->label('Latest version')
                    ->badge()
                    ->placeholder('Unreleased'),
                TextColumn::make('source.name')
                    ->label('Source')
                    ->placeholder('None')
                    ->sortable(),
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
