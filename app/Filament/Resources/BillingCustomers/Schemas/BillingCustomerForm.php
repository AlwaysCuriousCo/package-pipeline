<?php

namespace App\Filament\Resources\BillingCustomers\Schemas;

use App\Enums\MerchantProvider;
use App\Models\Team;
use App\Models\User;
use Filament\Forms\Components\MorphToSelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class BillingCustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                MorphToSelect::make('billable')
                    ->types([
                        MorphToSelect\Type::make(User::class)
                            ->titleAttribute('name'),
                        MorphToSelect\Type::make(Team::class)
                            ->titleAttribute('name'),
                    ])
                    ->searchable()
                    ->required()
                    ->columnSpanFull()
                    ->live(),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Who the invoice names — routinely not who logs in.'),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->helperText('Where billing mail goes: receipts, dunning notices, trial reminders.'),
                Select::make('merchant')
                    ->options(MerchantProvider::class)
                    ->default(MerchantProvider::Manual)
                    ->required()
                    ->helperText('Manual for customers billed outside any processor. A customer created by checkout arrives as Stripe on its own.'),
                Select::make('billing_contact_user_id')
                    ->label('Billing contact')
                    ->relationship('billingContact', 'name')
                    ->searchable()
                    ->preload()
                    ->visible(fn (Get $get): bool => $get('billable_type') === Team::class)
                    ->helperText('The one member billing mail and the activation token go to. Required in practice for a team customer.'),

                Section::make('Business details')
                    ->description('Captured at checkout for merchant-billed customers; entered here for Manual ones. What EU B2B invoices are required to carry.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        TextInput::make('company_name')->maxLength(255),
                        TextInput::make('tax_id')
                            ->label('Tax / VAT ID')
                            ->maxLength(64),
                        TextInput::make('address.line1')->label('Address line 1'),
                        TextInput::make('address.line2')->label('Address line 2'),
                        TextInput::make('address.city')->label('City'),
                        TextInput::make('address.state')->label('State / region'),
                        TextInput::make('address.postal_code')->label('Postal code'),
                        TextInput::make('address.country')
                            ->label('Country')
                            ->maxLength(2)
                            ->helperText('Two-letter ISO code.'),
                    ]),
            ]);
    }
}
