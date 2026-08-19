<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Concerns\HidesBreadcrumbs;
use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    use HidesBreadcrumbs;

    protected static string $resource = InvoiceResource::class;
}
