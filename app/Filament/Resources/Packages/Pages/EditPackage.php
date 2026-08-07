<?php

namespace App\Filament\Resources\Packages\Pages;

use App\Filament\Resources\Packages\Actions\SyncPackageAction;
use App\Filament\Resources\Packages\PackageResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPackage extends EditRecord
{
    protected static string $resource = PackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SyncPackageAction::make(),
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
