<?php

namespace App\Filament\Resources\OutgoingWebhooks\Pages;

use App\Filament\Resources\OutgoingWebhooks\OutgoingWebhookResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditOutgoingWebhook extends EditRecord
{
    protected static string $resource = OutgoingWebhookResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
