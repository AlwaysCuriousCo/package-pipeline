<?php

namespace App\Notifications\Concerns;

use App\Services\AdminNotifier;
use Illuminate\Notifications\AnonymousNotifiable;

/**
 * How a notification AdminNotifier fans out decides which transport it is
 * currently being sent over.
 *
 * There are two kinds of notifiable here. A User is a person who reads these in
 * the panel's bell, so they get the database channel and nothing else. Everything
 * else — the Slack channel, an outgoing webhook endpoint — belongs to the
 * installation rather than to a person, and is routed to anonymously with
 * exactly one transport named.
 *
 * So an anonymous notifiable's own routes *are* the answer. Hard-coding
 * `['slack']` for it, which is what each of these did while Slack was the only
 * one, silently sent a webhook delivery to Slack's transport instead — and
 * would do it again the next time a channel was added.
 *
 * @see AdminNotifier
 */
trait RoutedByAdminNotifier
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable instanceof AnonymousNotifiable
            ? array_keys($notifiable->routes)
            : ['database'];
    }
}
