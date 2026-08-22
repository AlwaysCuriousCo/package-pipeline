<?php

namespace App\Merchants;

use App\Enums\MerchantProvider;
use App\Merchants\Values\CheckoutRequest;
use App\Merchants\Values\CheckoutSession;
use App\Merchants\Values\NormalisedEvent;
use App\Merchants\Values\RemoteInvoice;
use App\Merchants\Values\RemoteSubscription;
use App\Models\BillingCustomer;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use Illuminate\Http\Request;

/**
 * The driver for merchants the enum may one day name but nothing implements.
 *
 * The same shape as App\Sources\StubClient, for the same reason: it lets the
 * enum stay complete while drivers land one by one, and it documents by
 * refusal exactly which methods a new driver owes.
 */
class StubClient implements MerchantClient
{
    public function __construct(private readonly MerchantProvider $merchant) {}

    public function syncProduct(Plan $plan): string
    {
        throw $this->unsupported();
    }

    public function syncPrice(PlanPrice $price): string
    {
        throw $this->unsupported();
    }

    public function ensureCustomer(BillingCustomer $customer): string
    {
        throw $this->unsupported();
    }

    public function startCheckout(CheckoutRequest $request): CheckoutSession
    {
        throw $this->unsupported();
    }

    public function portalUrl(BillingCustomer $customer, string $returnUrl): string
    {
        throw $this->unsupported();
    }

    public function changePrice(Subscription $subscription, PlanPrice $price): void
    {
        throw $this->unsupported();
    }

    public function cancel(Subscription $subscription, bool $immediately): void
    {
        throw $this->unsupported();
    }

    public function fetchSubscription(string $externalId): RemoteSubscription
    {
        throw $this->unsupported();
    }

    public function fetchInvoices(BillingCustomer $customer): iterable
    {
        throw $this->unsupported();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function invoiceFromPayload(array $payload): RemoteInvoice
    {
        throw $this->unsupported();
    }

    public function customerExternalId(NormalisedEvent $event): ?string
    {
        throw $this->unsupported();
    }

    public function verifySignature(Request $request): NormalisedEvent
    {
        throw $this->unsupported();
    }

    private function unsupported(): UnsupportedMerchantException
    {
        return new UnsupportedMerchantException(
            "{$this->merchant->getLabel()} is not supported yet: the merchant is declared, but no driver implements it."
        );
    }
}
