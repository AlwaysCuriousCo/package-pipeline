<?php

namespace App\Filament\Concerns;

use Illuminate\Contracts\Support\Htmlable;

/**
 * Makes a record page's heading a GitHub-style switcher: the record's name
 * opens a searchable list of every record of the resource the user can see,
 * and picking one jumps to the same page for it. The title (breadcrumb,
 * browser tab) stays text.
 */
trait SwitchesRecords
{
    public function getHeading(): string|Htmlable|null
    {
        $resource = static::getResource();

        // ponytail: every visible record is rendered and filtered client-side;
        // move the search server-side when a registry outgrows a few thousand.
        return view('filament.resources.packages.package-switcher', [
            'current' => $this->getRecord(),
            'label' => $resource::getModelLabel(),
            'items' => $resource::getEloquentQuery()->orderBy('name')->pluck('name', 'id'),
            'href' => fn (int $id): string => $resource::getUrl(static::getResourcePageName(), ['record' => $id]),
        ]);
    }
}
