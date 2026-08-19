<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The canonical lifecycle of a subscription, whatever the merchant calls it.
 *
 * Every driver normalises its own vocabulary into these cases before anything
 * local reads it — Stripe's `incomplete_expired` and `unpaid`, a manual
 * subscription's plain dates, whatever a future Paddle driver reports. The
 * one question the rest of the app ever asks is grantsAccess(), so drivers
 * only need to get *that* mapping right; the finer cases exist for the panel
 * and the reconciler, which need to say why access stopped.
 *
 * PastDue grants access on purpose: it is the dunning window, where the
 * merchant is retrying a failed payment and the operator chose to keep the
 * customer's CI green while it does. The hard stops — dispute, refund,
 * suspension — do not pass through PastDue; they go straight to Suspended or
 * Canceled, which is what makes them immediate.
 *
 * @see \App\Services\Billing\SubscriptionProjector
 */
enum SubscriptionStatus: string implements HasLabel
{
    /** Checkout started, first payment not yet settled. Grants nothing. */
    case Incomplete = 'incomplete';

    /** In a trial period. Grants access; the merchant holds the clock. */
    case Trialing = 'trialing';

    /** Paid up. */
    case Active = 'active';

    /**
     * A renewal payment failed and the merchant is retrying. Access
     * continues until the merchant gives up (Unpaid) or the plan's own
     * grace_days run out, whichever the projector is watching.
     */
    case PastDue = 'past_due';

    /** Paused at the merchant. Grants nothing, but nothing is torn down remotely. */
    case Paused = 'paused';

    /** Ended by the customer or the operator. */
    case Canceled = 'canceled';

    /** The merchant exhausted its retries and gave up collecting. */
    case Unpaid = 'unpaid';

    /**
     * Withheld by an administrator without touching the billing
     * relationship — abuse, a dispute under investigation, licence sharing.
     * The one status only this app can set; no merchant event produces it
     * and no merchant event clears it.
     */
    case Suspended = 'suspended';

    /**
     * Ran its course. The terminal state of a one-time purchase whose
     * updates window has closed — nothing failed and nobody cancelled.
     */
    case Expired = 'expired';

    /**
     * The single question the entitlement projector asks. Everything else
     * on this enum is reporting.
     */
    public function grantsAccess(): bool
    {
        return match ($this) {
            self::Trialing, self::Active, self::PastDue => true,
            self::Incomplete, self::Paused, self::Canceled,
            self::Unpaid, self::Suspended, self::Expired => false,
        };
    }

    /**
     * Whether a subscription in this status has stopped granting for a
     * reason the plan's lapse behaviour should answer — as opposed to never
     * having granted (Incomplete) or being administratively withheld
     * (Suspended, where the punishment is the point and the lapse behaviour
     * would soften it).
     */
    public function isLapse(): bool
    {
        return match ($this) {
            self::Canceled, self::Unpaid, self::Expired, self::Paused => true,
            default => false,
        };
    }

    /**
     * The statuses the nightly reconciler re-pulls from the merchant: those
     * that grant access now, and those the merchant might still move.
     */
    public static function reconcilable(): array
    {
        return [self::Incomplete, self::Trialing, self::Active, self::PastDue, self::Paused];
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Incomplete => 'Incomplete',
            self::Trialing => 'Trialing',
            self::Active => 'Active',
            self::PastDue => 'Past due',
            self::Paused => 'Paused',
            self::Canceled => 'Canceled',
            self::Unpaid => 'Unpaid',
            self::Suspended => 'Suspended',
            self::Expired => 'Expired',
        };
    }

    /**
     * The badge colour Filament tables show this status in — the panel's
     * shorthand for "money is fine / money needs attention / access is off".
     */
    public function color(): string
    {
        return match ($this) {
            self::Active, self::Trialing => 'success',
            self::PastDue, self::Incomplete, self::Paused => 'warning',
            self::Canceled, self::Unpaid, self::Suspended, self::Expired => 'danger',
        };
    }
}
