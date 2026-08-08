<?php

namespace App\Filament\Resources\Packages\Pages;

use App\Filament\Resources\Packages\Actions\CreateWebhookAction;
use App\Filament\Resources\Packages\Actions\SyncPackageAction;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\Packages\Widgets\PackageSyncProgress;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPackage extends ViewRecord
{
    protected static string $resource = PackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SyncPackageAction::make(),
            CreateWebhookAction::make(),
            EditAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            PackageSyncProgress::class,
        ];
    }
}
