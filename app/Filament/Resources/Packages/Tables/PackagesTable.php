<?php

namespace App\Filament\Resources\Packages\Tables;

use App\Filament\Resources\Packages\Actions\ExportDownloadsAction;
use App\Filament\Resources\Packages\Actions\QueueSyncsBulkAction;
use App\Filament\Resources\Packages\Actions\RebuildPackageAction;
use App\Filament\Resources\Packages\Actions\SyncPackageAction;
use App\Models\Package;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PackagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            // Syncs finish on a worker, so the row that shows their result has
            // to come back for it rather than wait for the next navigation.
            ->poll('30s')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    // Abandonment is a property of the package rather than of
                    // any one sync, so it belongs against the name and not in
                    // a column of its own that would be empty for almost every
                    // row.
                    ->badge(fn (Package $record): bool => $record->abandoned)
                    ->color(fn (Package $record): ?string => $record->abandoned ? 'danger' : null)
                    ->tooltip(fn (Package $record): ?string => match (true) {
                        ! $record->abandoned => null,
                        filled($record->replacement_package) => "Abandoned — use {$record->replacement_package} instead.",
                        default => 'Abandoned.',
                    }),
                TextColumn::make('repository')
                    ->label('Repository')
                    ->searchable()
                    ->url(fn (Package $record): ?string => $record->repository)
                    ->openUrlInNewTab()
                    ->color('primary')
                    ->limit(50)
                    ->placeholder('Uploaded artifacts'),
                TextColumn::make('composerRepository.name')
                    ->label('Served in')
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('source.name')
                    ->label('Source')
                    ->badge()
                    ->color(fn (Package $record): string => $record->source?->isConnected() ? 'success' : 'warning')
                    ->searchable()
                    ->sortable()
                    ->placeholder('Unlinked')
                    ->toggleable(),
                TextColumn::make('latest_version')
                    ->label('Latest version')
                    ->badge()
                    ->searchable()
                    ->sortable()
                    ->placeholder('Unreleased'),
                TextColumn::make('type')
                    ->badge()
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('versions_count')
                    ->label('Versions')
                    ->counts('versions')
                    ->sortable(),
                TextColumn::make('total_downloads')
                    ->label('Downloads')
                    ->numeric()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_synced_at')
                    ->label('Last synced')
                    ->since()
                    ->sortable()
                    ->placeholder('Never')
                    // A failed sync shows up here without opening the record.
                    ->color(fn (Package $record): ?string => $record->sync_error ? 'danger' : null)
                    ->tooltip(fn (Package $record): ?string => $record->sync_error),
                TextColumn::make('description')
                    ->limit(60)
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('composerRepository')
                    ->label('Composer repository')
                    ->relationship('composerRepository', 'name')
                    ->multiple()
                    ->preload(),
                SelectFilter::make('source')
                    ->relationship('source', 'name')
                    ->multiple()
                    ->preload(),
                SelectFilter::make('type')
                    ->options(fn (): array => Package::types())
                    ->multiple(),
                Filter::make('unreleased')
                    ->label('Unreleased only')
                    ->query(fn (Builder $query): Builder => $query->whereNull('latest_version')),
                // The other half of the navigation badge: it says how many
                // packages stopped syncing, this is how they are found.
                Filter::make('sync_failing')
                    ->label('Sync failing')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('sync_error')),
                // A package can stop syncing without recording an error —
                // a webhook that no longer delivers and a scheduled run that
                // never reached it look exactly like a quiet repository until
                // the timestamp is compared against the hourly schedule.
                // Packages published by artifact upload never sync at all, so
                // they are not stale, they are simply not synced.
                Filter::make('stale')
                    ->label('Not synced in 24 hours')
                    ->query(fn (Builder $query): Builder => $query
                        ->whereNotNull('repository')
                        ->where(fn (Builder $query): Builder => $query
                            ->whereNull('last_synced_at')
                            ->orWhere('last_synced_at', '<', now()->subDay()))),
            ])
            ->recordActions([
                SyncPackageAction::make(),
                RebuildPackageAction::make(),
                ExportDownloadsAction::make(),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    QueueSyncsBulkAction::sync(),
                    QueueSyncsBulkAction::rebuild(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
