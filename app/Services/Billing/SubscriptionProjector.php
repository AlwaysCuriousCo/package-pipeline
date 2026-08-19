<?php

namespace App\Services\Billing;

use App\Merchants\Values\RemoteSubscription;
use App\Models\Subscription;

/**
 * Applies a merchant's truth to the local subscription row, then re-projects
 * the grants behind it.
 *
 * One entry point for both of the paths merchant truth arrives by — a
 * verified webhook and the nightly reconciler — so there is exactly one
 * translation of "what Stripe said" into "what the row says", and the two
 * paths cannot drift.
 *
 * Out-of-order deliveries are the failure mode this class exists to survive:
 * webhooks carry no ordering guarantee, and a delayed `updated` arriving
 * after a `deleted` must not resurrect access. Every RemoteSubscription
 * carries the moment it describes, the row keeps the newest moment already
 * applied, and anything older is discarded unapplied.
 */
final class SubscriptionProjector
{
    public function __construct(
        private readonly EntitlementProjector $entitlements = new EntitlementProjector,
    ) {}

    public function apply(Subscription $subscription, RemoteSubscription $remote): void
    {
        if ($subscription->last_event_at?->gt($remote->asOf)) {
            return;
        }

        $subscription->forceFill([
            'status' => $remote->status,
            'quantity' => $remote->quantity,
            'trial_ends_at' => $remote->trialEndsAt,
            'current_period_start' => $remote->currentPeriodStart,
            'current_period_end' => $remote->currentPeriodEnd,
            'cancel_at' => $remote->cancelAt,
            'canceled_at' => $remote->canceledAt,
            'ends_at' => $remote->endedAt,
            'coupon_code' => $remote->couponCode ?? $subscription->coupon_code,
            'last_event_at' => $remote->asOf,
        ]);

        $this->stampGrace($subscription);

        $subscription->save();

        $this->entitlements->project($subscription);
    }

    /**
     * Start the plan's own grace clock the moment the status stops granting,
     * and stop it the moment granting resumes.
     *
     * The clock starts once per lapse — a second Unpaid event must not push
     * the deadline out — and only when the plan configures a grace of its
     * own on top of whatever dunning the merchant already ran.
     */
    private function stampGrace(Subscription $subscription): void
    {
        if ($subscription->status->grantsAccess()) {
            $subscription->grace_ends_at = null;

            return;
        }

        $graceDays = $subscription->plan->grace_days;

        if ($graceDays === null || $graceDays === 0) {
            return;
        }

        // Only a money lapse earns grace: an Incomplete checkout never had
        // access to extend, and Suspended is the operator's hard stop.
        if (! $subscription->status->isLapse()) {
            return;
        }

        $subscription->grace_ends_at ??= now()->addDays($graceDays);
    }
}
