<?php

namespace App\Filament\Resources\AuthenticationSources\Pages;

use App\Filament\Resources\AuthenticationSources\AuthenticationSourceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAuthenticationSource extends EditRecord
{
    protected static string $resource = AuthenticationSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
