<?php

namespace App\Merchants\Values;

use Carbon\CarbonImmutable;

/**
 * A merchant's invoice, normalised for mirroring into the invoices table.
 * Amounts are integer minor units, as everywhere.
 */
final readonly class RemoteInvoice
{
    public function __construct(
        public string $externalId,
        public string $customerExternalId,
        public ?string $subscriptionExternalId,
        public ?string $number,
        public string $currency,
        public int $subtotal,
        public int $tax,
        public int $total,
        public int $amountRefunded,
        public string $status,
        public ?string $hostedUrl,
        public ?string $pdfUrl,
        public ?CarbonImmutable $issuedAt,
        public ?CarbonImmutable $paidAt,
        public ?CarbonImmutable $refundedAt,
    ) {}
}
