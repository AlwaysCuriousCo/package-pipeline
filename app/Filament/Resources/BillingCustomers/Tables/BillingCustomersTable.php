<?php

namespace App\Filament\Resources\BillingCustomers\Tables;

use App\Models\BillingCustomer;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BillingCustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (BillingCustomer $record): string => $record->email),
                TextColumn::make('billable_type')
                    ->label('Kind')
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('billable.name')
                    ->label('Account'),
                TextColumn::make('merchant')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('subscriptions_count')
                    ->label('Subscriptions')
                    ->counts('subscriptions')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('company_name')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->emptyStateHeading('No billing customers')
            ->emptyStateDescription('A billing customer is the payer behind an account — created by checkout, or here for customers billed by hand.');
    }
}
