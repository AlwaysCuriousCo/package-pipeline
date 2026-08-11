<?php

namespace App\Filament\Resources\Teams\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TeamsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('users_count')
                    ->label('Members')
                    ->counts('users')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                // The two counts that say what a team is actually for. A team
                // with members and no grants is a group that reaches nothing,
                // which is worth being able to see at a glance.
                TextColumn::make('repositories_count')
                    ->label('Repositories')
                    ->counts('repositories')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('packages_count')
                    ->label('Packages')
                    ->counts('packages')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('description')
                    ->limit(60)
                    ->placeholder('-')
                    ->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No teams')
            ->emptyStateDescription('A team holds package and repository grants for everyone in it, so onboarding is adding a person rather than repeating a set of grants.');
    }
}
