<?php

namespace App\Notifications\Contracts;

use App\Enums\WebhookEvent;
use App\Services\AdminNotifier;

/**
 * A notification that outgoing webhooks can be subscribed to.
 *
 * Implementing this is what puts a notification on the wire; a notification
 * without it reaches the panel's bell and Slack as before and no further. That
 * is deliberate — an endpoint subscribes to a named event with a documented
 * payload, so widening the set has to be an act rather than a side effect of
 * adding a notification class.
 *
 * @see AdminNotifier where the fan-out reads this
 * @see docs/outgoing-webhooks.md for the payloads, which are published API
 */
interface SendsWebhook
{
    /**
     * Which event this is, as endpoints subscribe to it.
     */
    public function webhookEvent(): WebhookEvent;

    /**
     * The `data` object of the delivered payload.
     *
     * Keys are additive: a receiver may rely on one continuing to exist and to
     * mean what it meant. Anything derived from a package should carry enough
     * for a receiver to act without calling back — the name, at minimum, since
     * a deploy pipeline's whole filter is usually the name.
     *
     * @return array<string, mixed>
     */
    public function toWebhook(): array;
}
