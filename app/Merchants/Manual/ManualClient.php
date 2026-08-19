<?php

namespace App\Merchants\Manual;

use App\Merchants\MerchantClient;
use App\Merchants\UnsupportedMerchantException;
use App\Merchants\Values\CheckoutRequest;
use App\Merchants\Values\CheckoutSession;
use App\Merchants\Values\NormalisedEvent;
use App\Merchants\Values\RemoteSubscription;
use App\Models\BillingCustomer;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The merchant with nobody behind it: subscriptions an administrator made.
 *
 * Comped accounts, wire transfers, purchase orders, sponsorships — money (or
 * its absence) that never touches a payment processor, recorded as
 * first-class subscriptions so the entitlement projector treats them exactly
 * like paid ones. Also the proof that MerchantClient holds for a driver with
 * no network at all, which is what keeps the contract honest.
 *
 * The sync methods succeed by minting a local pseudo-id: there is no remote
 * side to be out of sync with. The interactive methods refuse — there is no
 * checkout to hold and no portal to visit — and the refusal message is what
 * a customer-area page shows when a Manual customer clicks where a card
 * button would be.
 */
class ManualClient implements MerchantClient
{
    public function syncProduct(Plan $plan): string
    {
        return 'manual_product_'.$plan->getKey();
    }

    public function syncPrice(PlanPrice $price): string
    {
        return 'manual_price_'.$price->getKey();
    }

    public function ensureCustomer(BillingCustomer $customer): string
    {
        return 'manual_customer_'.($customer->getKey() ?? Str::lower(Str::random(12)));
    }

    public function startCheckout(CheckoutRequest $request): CheckoutSession
    {
        throw new UnsupportedMerchantException(
            'Manual subscriptions have no checkout: an administrator creates them in the panel.'
        );
    }

    public function portalUrl(BillingCustomer $customer, string $returnUrl): string
    {
        throw new UnsupportedMerchantException(
            'Manual customers have no billing portal: there is no card on file to manage.'
        );
    }

    public function changePrice(Subscription $subscription, PlanPrice $price): void
    {
        // Nothing remote to move; the local row is the whole record.
    }

    public function cancel(Subscription $subscription, bool $immediately): void
    {
        // Nothing remote to cancel. SubscriptionProjector applies the local
        // cancellation directly for Manual subscriptions.
    }

    public function fetchSubscription(string $externalId): RemoteSubscription
    {
        throw new UnsupportedMerchantException(
            'Manual subscriptions have no remote record to fetch: the local row is the truth.'
        );
    }

    public function fetchInvoices(BillingCustomer $customer): iterable
    {
        return [];
    }

    public function invoiceFromPayload(array $payload): \App\Merchants\Values\RemoteInvoice
    {
        throw new UnsupportedMerchantException(
            'Manual has no invoices to translate: nothing bills its customers.'
        );
    }

    public function customerExternalId(NormalisedEvent $event): ?string
    {
        return null;
    }

    public function verifySignature(Request $request): NormalisedEvent
    {
        throw new UnsupportedMerchantException(
            'Manual is not a webhook sender: nothing signs deliveries for it.'
        );
    }
}
