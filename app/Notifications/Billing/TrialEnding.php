<?php

namespace App\Notifications\Billing;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The trial converts to a charge in a few days — the one email that saves a
 * surprise invoice, sent once per subscription by the nightly reconcile.
 */
class TrialEnding extends CustomerBillingMail implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Subscription $subscription) {}

    protected function title(): string
    {
        return "Your {$this->subscription->plan->name} trial ends soon";
    }

    protected function body(): string
    {
        $ends = $this->subscription->trial_ends_at?->toFormattedDateString() ?? 'soon';

        return "Your trial ends on {$ends}, when the first charge is made. Cancel before then from your billing area if you do not want to continue.";
    }
}
