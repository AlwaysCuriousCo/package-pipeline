<?php

namespace App\Notifications\Billing;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The purchase worked and access is on.
 *
 * Deliberately does not carry the activation token: the welcome page shows
 * it once, and a credential in an inbox is the exact shape this registry
 * already avoids putting setup links in.
 */
class SubscriptionActivated extends CustomerBillingMail implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Subscription $subscription) {}

    protected function mailTone(): string
    {
        return 'success';
    }

    protected function title(): string
    {
        return "Your {$this->subscription->plan->name} subscription is active";
    }

    protected function body(): string
    {
        return 'Access to the packages your plan includes is on. Tokens, invoices and your card are managed from your billing area.';
    }
}
