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
     * entry an admin could add by hand is one nobody could read as evidence.
     *
     * That is a statement about this app, not about the table — see
     * App\Policies\ActivityPolicy for what it does and does not buy.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * Said on the page as well as on the Roles screen, because the two readers
     * are different people: one is deciding whether to grant this, the other
     * is deciding whether what they are looking at is the whole story.
     */
    public function getSubheading(): string
    {
        return 'A complete history of every change in the registry.';
    }
}
