<?php

namespace App\Filament\Resources\DeployTokens\Pages;

use App\Filament\Resources\DeployTokens\DeployTokenResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDeployTokens extends ListRecords
{
    protected static string $resource = DeployTokenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
