<?php

namespace App\Filament\Resources\Sources\Pages;

use App\Filament\Resources\Sources\Actions\ConnectSourceAction;
use App\Filament\Resources\Sources\Actions\DisconnectSourceAction;
use App\Filament\Resources\Sources\Actions\TestSourceAction;
use App\Filament\Resources\Sources\SourceResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewSource extends ViewRecord
{
    protected static string $resource = SourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ConnectSourceAction::make(),
            TestSourceAction::make(),
            EditAction::make(),
            DisconnectSourceAction::make(),
        ];
    }
}
