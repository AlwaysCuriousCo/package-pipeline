<?php

namespace App\Filament\Resources\OutgoingWebhooks\Pages;

use App\Filament\Concerns\HidesBreadcrumbs;
use App\Filament\Resources\OutgoingWebhooks\OutgoingWebhookResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOutgoingWebhooks extends ListRecords
{
    use HidesBreadcrumbs;

    protected static string $resource = OutgoingWebhookResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
