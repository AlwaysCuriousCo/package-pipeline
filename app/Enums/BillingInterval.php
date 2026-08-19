<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * How often a price charges.
 *
 * The value is what crosses the merchant driver contract: Stripe's own
 * interval vocabulary happens to match `month`/`year`, and OneTime is the
 * absence of an interval — a price the driver mirrors as a non-recurring
 * Price object. Weeks and days are deliberately not offered; nobody sells
 * package access by the week, and every case here is a case every future
 * driver must translate.
 */
enum BillingInterval: string implements HasLabel
{
    case Month = 'month';
    case Year = 'year';
    case OneTime = 'one_time';

    public function getLabel(): string
    {
        return match ($this) {
            self::Month => 'Monthly',
            self::Year => 'Yearly',
            self::OneTime => 'One-time',
        };
    }

    public function recurring(): bool
    {
        return $this !== self::OneTime;
    }
}
