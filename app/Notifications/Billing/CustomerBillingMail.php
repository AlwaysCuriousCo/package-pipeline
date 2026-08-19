<?php

namespace App\Notifications\Billing;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The customer-facing half of billing mail.
 *
 * The admin announcements route through RoutedByAdminNotifier because their
 * audience is "everyone with a role"; these go to exactly one person — the
 * payer, or a team's billing contact — and never to the bell or Slack, which
 * a customer cannot see. The rendering reuses mail.announcement so a billing
 * email and an admin email are recognisably from the same registry, with the
 * footer pointing at the customer's own billing area rather than the panel.
 */
abstract class CustomerBillingMail extends Notification
{
    abstract protected function title(): string;

    abstract protected function body(): string;

    /**
     * @return array{label: string, url: string}
     */
    protected function mailAction(): array
    {
        return ['label' => 'Manage billing', 'url' => route('billing.index')];
    }

    protected function mailTone(): string
    {
        return 'info';
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $action = $this->mailAction();

        return (new MailMessage)
            ->subject($this->title())
            ->view('mail.announcement', [
                'appName' => (string) config('app.name'),
                'title' => $this->title(),
                'body' => $this->body(),
                'actionLabel' => $action['label'],
                'actionUrl' => $action['url'],
                'tone' => $this->mailTone(),
                'email' => $notifiable->email ?? null,
                'preferencesUrl' => route('billing.index'),
            ]);
    }
}
