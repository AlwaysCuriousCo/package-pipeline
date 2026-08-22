<?php

namespace App\Filament\Resources\Plans\Pages;

use App\Filament\Resources\Plans\PlanResource;
use App\Jobs\ReprojectPlanEntitlements;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * Editing a plan's entitlements changes what every subscriber holds, so
     * every subscription re-projects — on the queue, because a popular plan
     * is thousands of customers and this request is one person's save.
     */
    protected function afterSave(): void
    {
        ReprojectPlanEntitlements::dispatch($this->record->getKey());
    }
}
