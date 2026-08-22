<?php

namespace App\Notifications\Billing;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The signed link that proves a self-registered address is real — required
 * before checkout, so a fabricated address cannot buy a trial.
 */
class VerifyBillingEmail extends CustomerBillingMail implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $url) {}

    protected function mailAction(): array
    {
        return ['label' => 'Verify email', 'url' => $this->url];
    }

    protected function title(): string
    {
        return 'Verify your email address';
    }

    protected function body(): string
    {
        return 'Confirm this address to finish setting up your account. The link is valid for a day; ask for another from your billing page if it expires.';
    }
}
