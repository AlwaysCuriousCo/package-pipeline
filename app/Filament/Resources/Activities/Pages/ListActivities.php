<?php

namespace App\Filament\Resources\Activities\Pages;

use App\Filament\Concerns\HidesBreadcrumbs;
use App\Filament\Resources\Activities\ActivityResource;
use Filament\Resources\Pages\ListRecords;

class ListActivities extends ListRecords
{
    use HidesBreadcrumbs;

    protected static string $resource = ActivityResource::class;

    /**
     * No header actions: the log is written by the records it audits, and an
     * entry an admin could add by hand is an entry nobody could trust.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
