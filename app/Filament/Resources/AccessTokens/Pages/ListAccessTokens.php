<?php

namespace App\Filament\Resources\AccessTokens\Pages;

use App\Filament\Concerns\HidesBreadcrumbs;
use App\Filament\Resources\AccessTokens\AccessTokenResource;
use Filament\Resources\Pages\ListRecords;

class ListAccessTokens extends ListRecords
{
    use HidesBreadcrumbs;

    protected static string $resource = AccessTokenResource::class;
}
