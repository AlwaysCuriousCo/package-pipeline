<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * When a customer's own cancellation takes their access away.
 *
 * The industry norm is end-of-period: the customer paid for the month, so the
 * month is theirs, and the subscription simply does not renew. Immediate is
 * the stricter reading — cancelling is renouncing — and it is what this
 * registry's operator chose as the default, so it is first and the plan form
 * defaults to it. Either way the merchant is told the same thing
 * (cancel now / cancel at period end) and the local record follows the
 * merchant's answer, so the two clocks cannot disagree.
 */
enum CancellationTiming: string implements HasDescription, HasLabel
{
    case Immediate = 'immediate';
    case EndOfPeriod = 'end_of_period';

    public function getLabel(): string
    {
        return match ($this) {
            self::Immediate => 'Immediately',
            self::EndOfPeriod => 'At the end of the paid period',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Immediate => 'Cancelling withdraws access at once, before the paid period runs out.',
            self::EndOfPeriod => 'Cancelling stops the renewal; access continues until the period the customer already paid for ends.',
        };
    }
}
