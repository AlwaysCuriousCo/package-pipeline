<?php

namespace App\Filament\Resources\OutgoingWebhooks\Pages;

use App\Filament\Resources\OutgoingWebhooks\OutgoingWebhookResource;
use Filament\Resources\Pages\CreateRecord;

class CreateOutgoingWebhook extends CreateRecord
{
    protected static string $resource = OutgoingWebhookResource::class;
}
