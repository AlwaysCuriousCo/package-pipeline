<?php

namespace App\Notifications\Billing;

use App\Enums\LapseBehaviour;
use App\Models\Subscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The subscription stopped granting access, and what that means under this
 * plan — withdrawn, frozen at the versions already released, or tokens
 * revoked — said plainly rather than left to be discovered by a failing
 * composer install.
 */
class SubscriptionLapsed extends CustomerBillingMail implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Subscription $subscription) {}

    protected function mailTone(): string
    {
        return 'danger';
    }

    protected function title(): string
    {
        return "Your {$this->subscription->plan->name} subscription has ended";
    }

    protected function body(): string
    {
        return match ($this->subscription->plan->lapse_behaviour) {
            LapseBehaviour::FreezeAtVersion => 'Versions released while you were subscribed remain yours to install. New releases need the subscription renewed.',
            LapseBehaviour::RevokeTokens => 'Access has been withdrawn and the subscription\'s tokens revoked. Renewing restores access; new tokens are issued from your billing area.',
            LapseBehaviour::None => 'Your access continues. Renew any time to keep supporting the packages.',
            LapseBehaviour::WithdrawEntitlement => 'Access to the plan\'s packages has been withdrawn. Renewing restores it exactly as it was.',
        };
    }
}
