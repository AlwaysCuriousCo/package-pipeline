<?php

namespace App\Filament\Resources\Repositories\Tables;

use App\Models\Repository;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RepositoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('path')
                    ->label('Served at')
                    ->formatStateUsing(fn (?string $state): string => "/r/{$state}")
                    // The default repository's null path is the registry root,
                    // not a missing value.
                    ->placeholder('/ (registry root)')
                    ->sortable(),
                IconColumn::make('public')
                    ->boolean()
                    ->tooltip(fn (Repository $record): string => $record->public
                        ? 'Readable without a token'
                        : 'Requires an access token'),
                TextColumn::make('packages_count')
                    ->label('Packages')
                    ->counts('packages')
                    ->sortable(),
                TextColumn::make('reserved_vendors_count')
                    ->label('Reserved vendors')
                    ->counts('reservedVendors')
                    ->badge()
                    ->color('gray')
                    ->tooltip('Vendor prefixes only this repository may introduce package names under.')
                    // Nothing reserved is the ordinary case and reads better as
                    // a blank cell than as a column of zeroes.
                    ->placeholder('—')
                    ->formatStateUsing(fn (int $state): ?string => $state === 0 ? null : (string) $state)
                    ->sortable(),
                TextColumn::make('description')
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    // The database restricts the delete anyway; disabling it
                    // here says why instead of failing with a query error. The
                    // default repository is simply not deletable — the root
                    // mount has to resolve to something.
                    ->disabled(fn (Repository $record): bool => $record->isDefault() || $record->packages()->exists())
                    ->tooltip(fn (Repository $record): ?string => match (true) {
                        $record->isDefault() => 'The default repository cannot be deleted.',
                        $record->packages()->exists() => 'Move or delete its packages first.',
                        default => null,
                    }),
            ]);
    }
}
