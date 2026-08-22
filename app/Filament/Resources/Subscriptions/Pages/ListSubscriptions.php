<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Concerns\HidesBreadcrumbs;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSubscriptions extends ListRecords
{
    use HidesBreadcrumbs;

    protected static string $resource = SubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New manual subscription'),
        ];
    }
}
