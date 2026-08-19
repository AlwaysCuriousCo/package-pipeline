<?php

namespace App\Merchants\Values;

use Carbon\CarbonImmutable;

/**
 * A verified webhook delivery, reduced to what the processor acts on.
 *
 * `kind` is this app's own small vocabulary — the processor switches on it,
 * never on a merchant's event names. A driver maps its dozens of event types
 * down to these few, and anything that maps to Ignore was verified, recorded
 * and deliberately not acted upon.
 */
final readonly class NormalisedEvent
{
    public const string SUBSCRIPTION_CHANGED = 'subscription_changed';

    public const string INVOICE_PAID = 'invoice_paid';

    public const string INVOICE_PAYMENT_FAILED = 'invoice_payment_failed';

    public const string INVOICE_REFUNDED = 'invoice_refunded';

    public const string DISPUTE_OPENED = 'dispute_opened';

    public const string CHECKOUT_COMPLETED = 'checkout_completed';

    public const string IGNORE = 'ignore';

    public function __construct(
        public string $externalId,
        public string $type,
        public string $kind,
        public array $payload,
        public CarbonImmutable $occurredAt,
    ) {}
}
