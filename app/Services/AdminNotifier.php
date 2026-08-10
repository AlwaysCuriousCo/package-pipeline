<?php

namespace App\Services;

use App\Models\OutgoingWebhook;
use App\Models\User;
use App\Notifications\Contracts\SendsWebhook;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifications;

/**
 * Sends a notification everywhere this installation wants to hear about it.
 *
 * There is no per-user preference to consult: every admin of a private
 * registry cares about the same handful of events, so the fan-out is the
 * panel's bell for each of them plus the Slack channel, when one is
 * configured, plus every outgoing webhook subscribed to the event. Keeping that
 * decision here means a caller only has to know what happened, not who is
 * listening.
 */
class AdminNotifier
{
    public function send(Notification $notification): void
    {
        Notifications::send($this->recipients(), $notification);

        if (($channel = $this->slackChannel()) !== null) {
            Notifications::route('slack', $channel)->notify($notification);
        }

        $this->deliverWebhooks($notification);
    }

    /**
     * Post the event to every endpoint that asked for it.
     *
     * Routed one endpoint at a time rather than to a collection, because each
     * is a separate delivery with its own secret, its own retries and its own
     * recorded outcome — one endpoint being down must have no bearing on
     * another's. The channel behind this queues each POST, so a whole fan-out
     * costs this method one dispatch apiece and no network at all.
     *
     * A notification that does not implement SendsWebhook is skipped before the
     * table is touched: most of what passes through here is panel news with no
     * event name and no documented payload, and the empty query would run on
     * every one of them.
     */
    private function deliverWebhooks(Notification $notification): void
    {
        if (! $notification instanceof SendsWebhook) {
            return;
        }

        foreach (OutgoingWebhook::listeningFor($notification->webhookEvent()) as $webhook) {
            Notifications::route('webhook', $webhook)->notify($notification);
        }
    }

    /**
     * The users who should hear about it: those holding a role, which is what
     * User::canAccessPanel() requires.
     *
     * A role-less account cannot open the panel, so a bell notification
     * addressed to it is one nobody will ever read — a row in the
     * notifications table that only ever accumulates.
     *
     * @return Collection<int, User>
     */
    private function recipients(): Collection
    {
        return User::query()->whereHas('roles')->get();
    }

    /**
     * The Slack channel to post to, or null when Slack is not set up. Both
     * halves are needed — a token with no channel has nowhere to go.
     */
    private function slackChannel(): ?string
    {
        $channel = config('services.slack.notifications.channel');

        return filled($channel) && filled(config('services.slack.notifications.bot_user_oauth_token'))
            ? (string) $channel
            : null;
    }
}
