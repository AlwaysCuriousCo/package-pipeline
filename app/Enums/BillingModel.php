<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * How a plan charges for what it grants.
 *
 * The two shapes commercial PHP packages are actually sold under, and they
 * differ in what "the subscription ended" means rather than in how the money
 * moves. A recurring plan that lapses stops granting; a perpetual plan that
 * lapses keeps granting what was released while it was paid for, forever. That
 * second promise is the whole reason LapseBehaviour::FreezeAtVersion and the
 * version ceiling exist.
 *
 * @see LapseBehaviour
 * @see \App\Services\Billing\VersionCeiling
 */
enum BillingModel: string implements HasDescription, HasLabel
{
    /** Renews on an interval; access lasts as long as the payments do. */
    case Recurring = 'recurring';

    /**
     * Paid once. Access to what was released during the updates window is
     * permanent; releases after it are not included.
     */
    case OneTimeWithUpdates = 'one_time_with_updates';

    public function getLabel(): string
    {
        return match ($this) {
            self::Recurring => 'Recurring subscription',
            self::OneTimeWithUpdates => 'One-time purchase with an updates window',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Recurring => 'Billed every period. Access continues for as long as the subscription is paid.',
            self::OneTimeWithUpdates => 'Billed once. The customer keeps every version released during the updates window, permanently, and stops receiving new ones when it closes.',
        };
    }

    /**
     * Whether a plan on this model has a renewal date at all. A one-time
     * purchase has an updates window that closes and nothing that renews, so
     * the reconciler has no remote subscription to re-pull and the dunning
     * settings on the plan mean nothing.
     */
    public function renews(): bool
    {
        return $this === self::Recurring;
    }

    /**
     * Whether the plan's updates window is the thing that decides how long
     * access to *new* releases lasts — as opposed to the billing period.
     */
    public function hasUpdatesWindow(): bool
    {
        return $this === self::OneTimeWithUpdates;
    }
}
