<?php

namespace App\Filament\Resources\BillingCustomers\Pages;

use App\Filament\Resources\BillingCustomers\BillingCustomerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBillingCustomer extends CreateRecord
{
    protected static string $resource = BillingCustomerResource::class;
}
