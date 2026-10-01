<?php

namespace App\Filament\Resources\Repositories\Pages;

use App\Filament\Resources\Repositories\RepositoryResource;
use App\Models\Repository;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

class EditRepository extends EditRecord
{
    protected static string $resource = RepositoryResource::class;

    /**
     * The same switcher the package view has: the name opens a list of every
     * repository, and picking one jumps to its edit page.
     */
    public function getHeading(): string|Htmlable|null
    {
        return view('filament.resources.packages.package-switcher', [
            'current' => $this->getRecord(),
            'label' => 'repository',
            'items' => Repository::query()->orderBy('name')->pluck('name', 'id'),
            'href' => fn (int $id): string => RepositoryResource::getUrl('edit', ['record' => $id]),
        ]);
    }
}
