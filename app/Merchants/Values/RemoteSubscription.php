<?php

namespace App\Merchants\Values;

use App\Enums\SubscriptionStatus;
use Carbon\CarbonImmutable;

/**
 * A merchant's subscription, translated into the canonical vocabulary.
 *
 * The one shape SubscriptionProjector reads, whether it arrived in a webhook
 * or a reconciler pull. Drivers do all their translating before this object
 * exists; nothing downstream knows what the merchant called anything.
 */
final readonly class RemoteSubscription
{
    public function __construct(
        public string $externalId,
        public string $customerExternalId,
        public SubscriptionStatus $status,
        public ?string $priceExternalId,
        public int $quantity,
        public ?CarbonImmutable $trialEndsAt,
        public ?CarbonImmutable $currentPeriodStart,
        public ?CarbonImmutable $currentPeriodEnd,
        public ?CarbonImmutable $cancelAt,
        public ?CarbonImmutable $canceledAt,
        public ?CarbonImmutable $endedAt,
        public ?string $couponCode,
        public CarbonImmutable $asOf,
    ) {}
}
