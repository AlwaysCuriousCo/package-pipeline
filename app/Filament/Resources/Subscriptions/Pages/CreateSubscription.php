<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Enums\MerchantProvider;
use App\Enums\SubscriptionStatus;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Subscription;
use App\Services\Billing\EntitlementProjector;
use App\Services\Billing\SubscriptionTokens;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Creates a Manual subscription: active from now, granted immediately.
 *
 * The one place a subscription is born without a merchant behind it. The
 * activation token is issued here too when the plan says so — its plain text
 * goes to the admin in a notification, because the buyer never saw a
 * checkout success page to read it from.
 */
class CreateSubscription extends CreateRecord
{
    protected static string $resource = SubscriptionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $subscription = Subscription::query()->create([
            ...$data,
            'merchant' => MerchantProvider::Manual,
            'status' => SubscriptionStatus::Active,
            'current_period_start' => now(),
            'last_event_at' => now(),
        ]);

        app(EntitlementProjector::class)->project($subscription);

        $token = app(SubscriptionTokens::class)->issueActivationToken($subscription);

        if ($token !== null) {
            Notification::make()
                ->title('Access token issued')
                ->body("Hand this to the customer — it is shown once: {$token->plainText}")
                ->persistent()
                ->success()
                ->send();
        }

        return $subscription;
    }
}
