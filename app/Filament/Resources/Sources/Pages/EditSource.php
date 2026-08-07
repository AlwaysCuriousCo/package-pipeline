<?php

namespace App\Filament\Resources\Sources\Pages;

use App\Filament\Resources\Sources\Actions\ConnectSourceAction;
use App\Filament\Resources\Sources\Actions\TestSourceAction;
use App\Filament\Resources\Sources\SourceResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditSource extends EditRecord
{
    protected static string $resource = SourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ConnectSourceAction::make(),
            TestSourceAction::make(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
