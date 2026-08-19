<?php

namespace App\Enums;

use App\Merchants\Manual\ManualClient;
use App\Merchants\MerchantClient;
use App\Merchants\Stripe\StripeClient;
use Filament\Support\Contracts\HasLabel;

/**
 * The payment merchants a subscription can be billed through.
 *
 * The same shape as SourceProvider: the enum is the registry of drivers, the
 * client() factory is the one place a case becomes an implementation, and a
 * case may exist before its driver does by resolving to a stub that refuses
 * loudly. Stripe and Manual are implemented.
 *
 * Manual is a real driver, not a test double. It is a subscription an
 * administrator created with no merchant behind it — a comped account, a wire
 * transfer, a purchase order — and it is also what proves the contract holds
 * for a driver with no network at all. Every subscription has a merchant;
 * "none" is spelled Manual.
 *
 * @see MerchantClient
 * @see \App\Sources\StubClient for the pattern this follows
 */
enum MerchantProvider: string implements HasLabel
{
    case Stripe = 'stripe';
    case Manual = 'manual';

    /**
     * The driver for this merchant — the single point where the enum's value
     * becomes behaviour, mirroring Package::client().
     */
    public function client(): MerchantClient
    {
        return match ($this) {
            self::Stripe => StripeClient::fromConfig(),
            self::Manual => new ManualClient,
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Stripe => 'Stripe',
            self::Manual => 'Manual / offline',
        };
    }

    /**
     * Whether this merchant can sell to a stranger: hosted checkout is what
     * the public buy button redirects to, so a plan whose merchant lacks it
     * can only be sold by an administrator.
     */
    public function supportsHostedCheckout(): bool
    {
        return match ($this) {
            self::Stripe => true,
            self::Manual => false,
        };
    }

    /** Whether customers manage their card and invoices on a merchant-hosted portal. */
    public function supportsPortal(): bool
    {
        return match ($this) {
            self::Stripe => true,
            self::Manual => false,
        };
    }

    /** Whether the merchant computes and collects tax at checkout. */
    public function supportsTax(): bool
    {
        return match ($this) {
            self::Stripe => (bool) config('services.stripe.tax_enabled'),
            self::Manual => false,
        };
    }

    /** Whether this merchant sends webhooks that need a signature check. */
    public function receivesWebhooks(): bool
    {
        return $this === self::Stripe;
    }
}
