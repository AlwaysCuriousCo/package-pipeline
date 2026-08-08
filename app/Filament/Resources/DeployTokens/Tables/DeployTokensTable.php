<?php

namespace App\Filament\Resources\DeployTokens\Tables;

use App\Models\DeployToken;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DeployTokensTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('token.token_prefix')
                    ->label('Token')
                    ->formatStateUsing(fn (string $state): string => "{$state}…")
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('Revoked'),
                TextColumn::make('scope')
                    ->state(fn (DeployToken $record): string => $record->isScoped()
                        ? implode(' · ', array_filter([
                            ($count = $record->repositories()->count()) ? "{$count} ".str('repository')->plural($count) : null,
                            ($count = $record->packages()->count()) ? "{$count} ".str('package')->plural($count) : null,
                        ]))
                        : 'Whole registry')
                    ->badge()
                    ->color(fn (DeployToken $record): string => $record->isScoped() ? 'gray' : 'warning'),
                TextColumn::make('token.last_used_at')
                    ->label('Last used')
                    ->since()
                    ->placeholder('Never'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->date()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalHeading('Delete deploy token')
                    ->modalDescription('Its access token stops authenticating immediately.'),
            ]);
    }
}
