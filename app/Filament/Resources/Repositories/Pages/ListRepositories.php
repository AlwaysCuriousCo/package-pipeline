<?php

namespace App\Filament\Resources\Repositories\Pages;

use App\Filament\Concerns\HidesBreadcrumbs;
use App\Filament\Resources\Repositories\RepositoryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRepositories extends ListRecords
{
    use HidesBreadcrumbs;

    protected static string $resource = RepositoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
