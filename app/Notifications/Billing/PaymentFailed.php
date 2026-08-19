<?php

namespace App\Notifications\Billing;

use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A renewal charge failed. Access continues while the merchant retries —
 * this email exists so the customer fixes the card before the retries run
 * out, not after their CI goes red.
 */
class PaymentFailed extends CustomerBillingMail implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Subscription $subscription) {}

    protected function mailTone(): string
    {
        return 'warning';
    }

    protected function mailAction(): array
    {
        return ['label' => 'Update payment method', 'url' => route('billing.portal')];
    }

    protected function title(): string
    {
        return "A payment for {$this->subscription->plan->name} failed";
    }

    protected function body(): string
    {
        return 'The charge will be retried automatically. Your access continues in the meantime — updating your card is usually all it takes.';
    }
}
