<?php

namespace App\Merchants\Values;

use App\Models\BillingCustomer;
use App\Models\PlanPrice;

/**
 * Everything a driver needs to start a purchase, gathered before any
 * merchant is spoken to.
 *
 * The pair of URLs is the hosted-checkout shape; when an embedded flow is
 * added later it will read the same request and ignore them, which is why
 * they are here rather than in a Stripe-only parameter bag.
 */
final readonly class CheckoutRequest
{
    public function __construct(
        public BillingCustomer $customer,
        public PlanPrice $price,
        public int $quantity,
        public ?string $couponCode,
        public string $successUrl,
        public string $cancelUrl,
        public bool $collectTax,
        public bool $collectBusinessDetails,
    ) {}
}
