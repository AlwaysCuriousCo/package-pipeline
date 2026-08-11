<?php

namespace App\Filament\Resources\Packages\Pages;

use App\Filament\Concerns\HidesBreadcrumbs;
use App\Filament\Resources\Packages\Actions\ExportDownloadsAction;
use App\Filament\Resources\Packages\Actions\ImportFromSourceAction;
use App\Filament\Resources\Packages\PackageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPackages extends ListRecords
{
    use HidesBreadcrumbs;

    protected static string $resource = PackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Registry-wide, narrowed to this admin's own grants by the export
            // itself — the same scoping the table below applies.
            ExportDownloadsAction::make(forRecord: false),
            ImportFromSourceAction::make(),
            CreateAction::make(),
        ];
    }
}
