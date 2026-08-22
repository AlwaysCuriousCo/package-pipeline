<?php

namespace App\Filament\Resources\Plans\Tables;

use App\Models\Plan;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Plan $record): string => $record->slug),
                TextColumn::make('billing_model')
                    ->label('Model')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('prices')
                    ->label('Prices')
                    ->state(fn (Plan $record): string => $record->prices
                        ->where('active', true)
                        ->map->display()
                        ->join(', ') ?: '—'),
                TextColumn::make('subscriptions_count')
                    ->label('Subscriptions')
                    ->counts('subscriptions')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                IconColumn::make('active')
                    ->boolean(),
                IconColumn::make('listed')
                    ->boolean()
                    ->toggleable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Existing subscriptions keep the plan (the delete is refused while any point at it); retiring usually means turning Active off instead.'),
            ])
            ->emptyStateHeading('No plans')
            ->emptyStateDescription('A plan names what a subscription grants, its prices, and every rule its lifecycle follows.');
    }
}
