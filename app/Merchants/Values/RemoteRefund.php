<?php

namespace App\Merchants\Values;

/**
 * A refund, reduced to what decides access: which purchase it reverses and
 * whether it reversed all of it.
 *
 * A purchase is found through its invoice when it has one, and through its
 * own reference (the checkout that bought it) when it does not, which is the
 * one-time purchase's case. Amounts are integer minor units, as everywhere.
 */
final readonly class RemoteRefund
{
    public function __construct(
        public ?string $invoiceExternalId,
        public ?string $purchaseExternalId,
        public int $amountRefunded,
        public ?bool $fullyRefunded,
    ) {}
}
