<?php

namespace App\Filament\Resources\OutgoingWebhooks\Actions;

use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\OutgoingWebhook;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Post a `ping` to one endpoint, so an operator finds out the URL is wrong now
 * rather than the next time a release depends on it.
 *
 * Queued like every other delivery, and for the same reason: this runs from a
 * panel request, and dialling somebody's endpoint inline would hang the page on
 * a receiver that never answers. The outcome therefore arrives in the table's
 * health column a moment later rather than in this action's own notification —
 * which is the honest arrangement anyway, since that column is what an operator
 * will be reading the next time they wonder.
 */
class SendTestDeliveryAction
{
    public static function make(): Action
    {
        return Action::make('test')
            ->label('Send test delivery')
            ->icon(Heroicon::OutlinedBolt)
            ->color('gray')
            // A delivery to a disabled endpoint is dropped by the job, so the
            // button would do nothing and say it had.
            ->visible(fn (OutgoingWebhook $record): bool => (bool) $record->active)
            ->action(function (OutgoingWebhook $record): void {
                DeliverWebhook::dispatch($record, WebhookEvent::Ping, [
                    'message' => 'This is a test delivery from '.config('app.name').'.',
                    'registry_url' => config('app.url'),
                ]);

                Notification::make()
                    ->success()
                    ->title('Test delivery queued')
                    ->body("A ping is on its way to {$record->url}. Refresh in a moment to see whether it was accepted.")
                    ->send();
            });
    }
}
