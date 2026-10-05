<?php

namespace App\Filament\Resources\Repositories\Pages;

use App\Filament\Concerns\SwitchesRecords;
use App\Filament\Resources\Repositories\RepositoryResource;
use Filament\Resources\Pages\EditRecord;

class EditRepository extends EditRecord
{
    use SwitchesRecords;

    protected static string $resource = RepositoryResource::class;
}
