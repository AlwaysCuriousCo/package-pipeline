<?php

namespace App\Services\Billing;

use App\Enums\TokenAbility;
use App\Models\Subscription;
use App\Models\Token;
use App\Support\NewToken;

/**
 * The tokens a subscription owns: the activation token a purchase mints, and
 * the seat count the plan's token_limit holds the line on.
 *
 * A subscription's tokens always belong to a person — the buyer, or a team's
 * billing contact — and carry only repository:read: they exist so `composer
 * install` works minutes after paying, not to administer the registry. More
 * tokens up to the cap are issued from the customer area through the same
 * path, so the cap has one enforcement point.
 */
final class SubscriptionTokens
{
    /**
     * The token activation mints, shown once on the welcome page — or null
     * when the plan does not auto-issue, the cap is already spent, or there
     * is nobody to own it (a team customer with no billing contact).
     */
    public function issueActivationToken(Subscription $subscription): ?NewToken
    {
        if (! $subscription->plan->auto_issue_token) {
            return null;
        }

        return $this->issueFor($subscription, "{$subscription->plan->name} subscription");
    }

    /**
     * Issue one more token under this subscription, inside the cap.
     */
    public function issueFor(Subscription $subscription, string $name): ?NewToken
    {
        $owner = $subscription->customer?->contact();

        if ($owner === null || ! $this->withinLimit($subscription)) {
            return null;
        }

        return Token::issue(
            $owner,
            $name,
            [TokenAbility::RepositoryRead],
            expiresAt: null,
            subscription: $subscription,
        );
    }

    /**
     * Whether the plan's token_limit leaves room for one more. Revoked
     * tokens do not count — the soft delete keeps them out of the relation —
     * so rolling a credential never eats a seat.
     */
    public function withinLimit(Subscription $subscription): bool
    {
        $limit = $subscription->plan->token_limit;

        return $limit === null || $subscription->tokens()->count() < $limit;
    }
}
