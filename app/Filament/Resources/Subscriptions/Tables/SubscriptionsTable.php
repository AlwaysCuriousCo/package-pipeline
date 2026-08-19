<?php

namespace App\Filament\Resources\Subscriptions\Tables;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SubscriptionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Subscription $record): string => $record->customer?->email ?? ''),
                TextColumn::make('plan.name')
                    ->label('Plan')
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (SubscriptionStatus $state): string => $state->color()),
                TextColumn::make('merchant')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('current_period_end')
                    ->label('Period ends')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('grace_ends_at')
                    ->label('Grace until')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('suspended_at')
                    ->label('Suspended')
                    ->dateTime()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(SubscriptionStatus::class),
                SelectFilter::make('plan')
                    ->relationship('plan', 'name'),
            ])
            ->recordActions([
                EditAction::make()->label('Manage'),
            ])
            ->emptyStateHeading('No subscriptions')
            ->emptyStateDescription('Merchant-billed subscriptions appear here when checkout completes; Manual ones are created here directly.');
    }
}
