<?php

namespace App\Merchants\Values;

/**
 * What starting a checkout produced.
 *
 * Today: a URL to redirect the buyer to, and the merchant's id for the
 * session so the completion webhook can be matched back. An embedded flow
 * later adds a client secret beside them without reshaping the contract.
 */
final readonly class CheckoutSession
{
    public function __construct(
        public string $externalId,
        public ?string $redirectUrl,
        public ?string $clientSecret = null,
    ) {}
}
