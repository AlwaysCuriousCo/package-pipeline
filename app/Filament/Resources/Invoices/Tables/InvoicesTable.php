<?php

namespace App\Filament\Resources\Invoices\Tables;

use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('issued_at', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('customer.name')
                    ->label('Customer')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('total')
                    ->formatStateUsing(fn (Invoice $record): string => strtoupper($record->currency).' '.number_format($record->total / 100, 2))
                    ->sortable(),
                TextColumn::make('amount_refunded')
                    ->label('Refunded')
                    ->formatStateUsing(fn (Invoice $record): string => $record->amount_refunded > 0
                        ? strtoupper($record->currency).' '.number_format($record->amount_refunded / 100, 2)
                        : '—'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'open', 'draft' => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('issued_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('View at merchant')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Invoice $record): ?string => $record->hosted_url, shouldOpenInNewTab: true)
                    ->visible(fn (Invoice $record): bool => $record->hosted_url !== null),
            ])
            ->emptyStateHeading('No invoices')
            ->emptyStateDescription('Invoices are mirrored here as the merchant issues them.');
    }
}
