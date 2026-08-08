<?php

namespace App\Filament\Resources\Sources\Pages;

use App\Filament\Concerns\HidesBreadcrumbs;
use App\Filament\Resources\Sources\Actions\ConnectGitHubAction;
use App\Filament\Resources\Sources\SourceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSources extends ListRecords
{
    use HidesBreadcrumbs;

    protected static string $resource = SourceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Connecting is the normal way in; the form behind "Add manually"
            // only exists for GitHub Enterprise and access-token sources.
            ConnectGitHubAction::make(),
            CreateAction::make()
                ->label('Add manually')
                ->color('gray')
                ->outlined(),
        ];
    }
}
