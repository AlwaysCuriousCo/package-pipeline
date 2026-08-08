<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;

class Dashboard extends BaseDashboard
{
    // The widgets carry their own titles; a "Dashboard" heading above them
    // adds nothing. Empty heading + no breadcrumbs collapses the whole
    // header block. The page keeps its title for the browser tab.
    public function getHeading(): string|Htmlable|null
    {
        return null;
    }
}
