<?php

namespace App\Filament\Resources\Invoices;

use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Tables\InvoicesTable;
use App\Models\Invoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Invoices: the local mirror of what the merchant issued. Read-only by
 * design — the merchant renders the documents; these rows exist so history
 * is browsable without a live API call and survives a merchant migration.
 *
 * @extends \Filament\Resources\Resource<Invoice>
 */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Commercial';

    protected static ?int $navigationSort = 40;

    public static function table(Table $table): Table
    {
        return InvoicesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
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
            'index' => ListInvoices::route('/'),
        ];
    }
}
