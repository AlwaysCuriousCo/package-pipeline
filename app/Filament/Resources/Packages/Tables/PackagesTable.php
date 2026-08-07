<?php

namespace App\Filament\Resources\Packages\Tables;

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
                    ->sortable(),
                TextColumn::make('repository')
                    ->label('Repository')
                    ->searchable()
                    ->url(fn (Package $record): string => $record->repository)
                    ->openUrlInNewTab()
                    ->color('primary')
                    ->limit(50),
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
            ])
            ->recordActions([
                SyncPackageAction::make(),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
