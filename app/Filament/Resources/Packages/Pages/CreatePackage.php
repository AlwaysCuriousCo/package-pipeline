<?php

namespace App\Filament\Resources\Packages\Pages;

use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\Packages\Schemas\PackageWizard;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;
use Filament\Schemas\Components\Wizard\Step;

class CreatePackage extends CreateRecord
{
    use HasWizard;

    protected static string $resource = PackageResource::class;

    /**
     * @return array<Step>
     */
    public function getSteps(): array
    {
        return PackageWizard::steps();
    }
}
