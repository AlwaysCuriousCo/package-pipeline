<?php

namespace App\Filament\Concerns;

trait HidesBreadcrumbs
{
    public function getBreadcrumbs(): array
    {
        return [];
    }
}
