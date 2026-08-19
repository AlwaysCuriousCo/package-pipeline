<?php

namespace App\Filament\Resources\BillingCustomers\Pages;

use App\Filament\Concerns\HidesBreadcrumbs;
use App\Filament\Resources\BillingCustomers\BillingCustomerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBillingCustomers extends ListRecords
{
    use HidesBreadcrumbs;

    protected static string $resource = BillingCustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
