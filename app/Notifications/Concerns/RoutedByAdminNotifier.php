<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use App\Services\AdminNotifier;
use Illuminate\Notifications\AnonymousNotifiable;

/**
 * How a notification AdminNotifier fans out decides which transport it is
 * currently being sent over.
 *
 * There are two kinds of notifiable here. A User is a person, who always reads
 * these in the panel's bell and is emailed them as well when the installation
 * and that person both asked for it. Everything else — the Slack channel, an
 * outgoing webhook endpoint — belongs to the installation rather than to a
 * person, and is routed to anonymously with exactly one transport named.
 *
 * So an anonymous notifiable's own routes *are* the answer. Hard-coding
 * `['slack']` for it, which is what each of these did while Slack was the only
 * one, silently sent a webhook delivery to Slack's transport instead — and
 * would do it again the next time a channel was added.
 *
 * The bell is not conditional and is not meant to be. It costs a row, it is
 * read where the work gets done, and an installation that has switched email
 * off has said something about its inbox rather than about wanting to hear
 * less.
 *
 * @see AdminNotifier
 * @see User::wantsMailAnnouncements()
 */
trait RoutedByAdminNotifier
{
    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return array_keys($notifiable->routes);
        }

        return $notifiable instanceof User && $notifiable->wantsMailAnnouncements()
            ? ['database', 'mail']
            : ['database'];
    }
}
