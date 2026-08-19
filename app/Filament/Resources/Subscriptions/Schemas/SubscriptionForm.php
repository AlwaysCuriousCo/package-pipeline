<?php

namespace App\Filament\Resources\Subscriptions\Schemas;

use App\Models\Plan;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * The create form only makes Manual subscriptions — comps, wire transfers,
 * purchase orders. Everything merchant-billed is created by checkout and
 * maintained by webhooks; editing its clocks here would just be overwritten
 * by the next event, so the edit page is actions, not fields.
 */
class SubscriptionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('billing_customer_id')
                    ->label('Customer')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->helperText('Create the customer first, under Billing customers.'),
                Select::make('plan_id')
                    ->label('Plan')
                    ->relationship('plan', 'name')
                    ->preload()
                    ->required()
                    ->live(),
                Select::make('plan_price_id')
                    ->label('Price')
                    ->options(fn (Get $get): array => Plan::query()
                        ->find($get('plan_id'))
                        ?->prices()
                        ->get()
                        ->mapWithKeys(fn ($price): array => [$price->getKey() => $price->display()])
                        ->all() ?? [])
                    ->required()
                    ->helperText('What this would cost if it were billed — kept for the record; nothing is charged.'),
                DateTimePicker::make('current_period_end')
                    ->label('Runs until')
                    ->helperText('Empty means indefinitely — the shape of a comp. A date makes it lapse there, under the plan\'s lapse behaviour, when the nightly reconcile passes it.'),
            ]);
    }
}
