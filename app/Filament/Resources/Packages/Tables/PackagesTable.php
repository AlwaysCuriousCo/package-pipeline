<?php

namespace App\Filament\Resources\Packages\Tables;

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
                SelectFilter::make('type')
                    ->options(fn (): array => Package::types())
                    ->multiple(),
                Filter::make('unreleased')
                    ->label('Unreleased only')
                    ->query(fn (Builder $query): Builder => $query->whereNull('latest_version')),
            ])
            ->recordActions([
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
