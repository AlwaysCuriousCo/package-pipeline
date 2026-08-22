<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\Actions\CancelSubscriptionAction;
use App\Filament\Resources\Subscriptions\Actions\SuspendSubscriptionAction;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Subscription;
use App\Services\Billing\EntitlementProjector;
use Filament\Resources\Pages\EditRecord;

/**
 * Managing a subscription is actions, not fields: the merchant owns the
 * clocks, and the two things an operator legitimately does — suspend and
 * cancel — change state through the same paths the webhooks use, so the
 * next event cannot quietly undo them.
 */
class EditSubscription extends EditRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SuspendSubscriptionAction::make(),
            CancelSubscriptionAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        $record = $this->record;

        if ($record instanceof Subscription) {
            app(EntitlementProjector::class)->project($record->refresh());
        }
    }
}
