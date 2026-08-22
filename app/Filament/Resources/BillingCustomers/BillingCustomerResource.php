<?php

namespace App\Filament\Resources\BillingCustomers;

use App\Filament\Resources\BillingCustomers\Pages\CreateBillingCustomer;
use App\Filament\Resources\BillingCustomers\Pages\EditBillingCustomer;
use App\Filament\Resources\BillingCustomers\Pages\ListBillingCustomers;
use App\Filament\Resources\BillingCustomers\Schemas\BillingCustomerForm;
use App\Filament\Resources\BillingCustomers\Tables\BillingCustomersTable;
use App\Models\BillingCustomer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Billing customers: the payer behind an account — who the card belongs to
 * and who the invoice names, kept apart from the account itself.
 *
 * @extends \Filament\Resources\Resource<BillingCustomer>
 */
class BillingCustomerResource extends Resource
{
    protected static ?string $model = BillingCustomer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|UnitEnum|null $navigationGroup = 'Commercial';

    protected static ?int $navigationSort = 30;

    protected static ?string $modelLabel = 'billing customer';

    public static function form(Schema $schema): Schema
    {
        return BillingCustomerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BillingCustomersTable::configure($table);
    }

    /**
     * The whole Commercial group stays out of the sidebar until billing is
     * turned on: a registry that has not enabled it shows exactly the panel
     * it always showed. The pages still answer by URL for administrators
     * setting things up ahead of the switch.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('registry.billing.enabled');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBillingCustomers::route('/'),
            'create' => CreateBillingCustomer::route('/create'),
            'edit' => EditBillingCustomer::route('/{record}/edit'),
        ];
    }
}
