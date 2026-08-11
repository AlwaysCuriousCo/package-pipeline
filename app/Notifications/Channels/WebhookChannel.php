<?php

namespace App\Notifications\Channels;

use App\Jobs\DeliverWebhook;
use App\Models\OutgoingWebhook;
use App\Notifications\Contracts\SendsWebhook;
use Illuminate\Notifications\Notification;

/**
 * The `webhook` notification channel: an HTTP POST, alongside `database` (the
 * panel's bell) and `slack`.
 *
 * A real channel rather than a call to the queue from AdminNotifier, so that
 * routing one — `Notification::route('webhook', $endpoint)->notify($event)` —
 * works from anywhere the other two do, and so a notification declares webhook
 * delivery in `via()` where a reader is already looking for it.
 *
 * The channel itself does not send anything. Handing the POST to a queued job
 * is the point: a notification is dispatched from inside a sync, and a channel
 * that dialled an operator's endpoint inline would put somebody else's HTTP
 * server in the middle of this registry's work.
 *
 * @see DeliverWebhook
 */
class WebhookChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $webhook = $notifiable->routeNotificationFor('webhook', $notification);

        // A notification with nothing to say on the wire is not an error: the
        // same object is sent to the bell and to Slack, and only some of them
        // are events an endpoint can subscribe to.
        if (! $webhook instanceof OutgoingWebhook || ! $notification instanceof SendsWebhook) {
            return;
        }

        DeliverWebhook::dispatch($webhook, $notification->webhookEvent(), $notification->toWebhook());
    }
}
