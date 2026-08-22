<?php

namespace App\Filament\Resources\Subscriptions\Actions;

use App\Enums\CancellationTiming;
use App\Enums\MerchantProvider;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Services\Billing\EntitlementProjector;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;

/**
 * Cancel a subscription from the operator's side.
 *
 * For a merchant-billed subscription the cancellation is sent to the
 * merchant, and the local row waits for the confirming event — the merchant
 * owns the clock, and a local edit it disagrees with would be repaired away
 * by the next webhook. A Manual subscription has no merchant to wait for,
 * so it is cancelled directly and re-projected on the spot.
 */
class CancelSubscriptionAction
{
    public static function make(): Action
    {
        return Action::make('cancel')
            ->label('Cancel')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Subscription $record): bool => ! in_array($record->status, [
                SubscriptionStatus::Canceled, SubscriptionStatus::Expired,
            ], true))
            ->schema([
                Toggle::make('immediately')
                    ->label('Cancel immediately')
                    ->default(fn (Subscription $record): bool => $record->plan->cancellation === CancellationTiming::Immediate)
                    ->helperText('Off lets the paid period run out; on withdraws access now.'),
            ])
            ->requiresConfirmation()
            ->action(function (Subscription $record, array $data): void {
                $immediately = (bool) $data['immediately'];

                if ($record->merchant !== MerchantProvider::Manual) {
                    $record->client()->cancel($record, $immediately);
                }

                if (! $immediately) {
                    // Access runs to the end of the paid period. The status
                    // stays what it is — Canceled would withdraw access on
                    // the next projection — and the boundary is crossed by
                    // the merchant's confirming event, or by the nightly
                    // reconcile for a Manual subscription.
                    $record->forceFill(['cancel_at' => $record->current_period_end])->save();

                    return;
                }

                $record->forceFill([
                    'status' => SubscriptionStatus::Canceled,
                    'canceled_at' => now(),
                    'ends_at' => now(),
                    'last_event_at' => now(),
                ])->save();

                app(EntitlementProjector::class)->project($record);
            });
    }
}
