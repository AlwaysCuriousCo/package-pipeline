<?php

namespace App\Filament\Resources\BillingCustomers\Pages;

use App\Filament\Resources\BillingCustomers\BillingCustomerResource;
use Filament\Resources\Pages\EditRecord;

class EditBillingCustomer extends EditRecord
{
    protected static string $resource = BillingCustomerResource::class;
}
