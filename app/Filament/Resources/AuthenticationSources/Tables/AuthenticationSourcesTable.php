<?php

namespace App\Filament\Resources\AuthenticationSources\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AuthenticationSourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('provider')
                    ->badge()
                    ->sortable(),
                IconColumn::make('active')
                    ->boolean(),
                IconColumn::make('allow_registration')
                    ->label('Registration')
                    ->boolean(),
                TextColumn::make('default_role')
                    ->label('New accounts get')
                    ->badge()
                    ->color('gray')
                    ->placeholder('No role'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Users who signed in through this provider keep their accounts; the login button disappears.'),
            ]);
    }
}
