<?php

namespace App\Filament\Resources\AuthenticationSources\Pages;

use App\Filament\Concerns\HidesBreadcrumbs;
use App\Filament\Resources\AuthenticationSources\AuthenticationSourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAuthenticationSources extends ListRecords
{
    use HidesBreadcrumbs;

    protected static string $resource = AuthenticationSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
