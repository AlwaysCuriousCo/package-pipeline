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
 * What a payment merchant does for this registry.
 *
 * The billing layer's counterpart of RepositoryClient: one interface, one
 * implementation per merchant, resolved through MerchantProvider::client().
 * Everything above this line speaks the canonical vocabulary — Plan rows,
 * SubscriptionStatus, integer minor units — and everything below it
 * translates. A driver that cannot do one of these honestly throws
 * UnsupportedMerchantException rather than pretending.
 *
 * The local database is the source of truth for the catalog and the merchant
 * is the source of truth for the billing clock; the sync methods push the
 * former out, the fetch methods pull the latter back, and external ids are
 * remembered in MerchantReference rather than as columns, so one row can be
 * known to several merchants.
 *
 * @see MerchantProvider
 * @see docs/plans/ecommerce-subscriptions.md
 */
interface MerchantClient
{
    /**
     * Ensure the plan exists at the merchant as a sellable product, and
     * return its external id there.
     */
    public function syncProduct(Plan $plan): string;

    /**
     * Ensure the price exists at the merchant, attached to its plan's
     * product, and return its external id. Merchants treat prices as
     * immutable — a changed amount is a new external price, and the old one
     * is archived, never edited.
     */
    public function syncPrice(PlanPrice $price): string;

    /**
     * Ensure the customer exists at the merchant, with the identity and tax
     * details an invoice needs, and return its external id.
     */
    public function ensureCustomer(BillingCustomer $customer): string;

    /**
     * Begin a purchase, returning where to send the buyer.
     */
    public function startCheckout(CheckoutRequest $request): CheckoutSession;

    /**
     * Where this customer manages their card, invoices and cancellation —
     * the merchant-hosted portal, entered with a one-time URL.
     */
    public function portalUrl(BillingCustomer $customer, string $returnUrl): string;

    /**
     * Move a subscription to a different price — an upgrade or downgrade.
     * Proration is the merchant's own policy, configured there.
     */
    public function changePrice(Subscription $subscription, PlanPrice $price): void;

    /**
     * Cancel at the merchant: immediately, or at the end of the period
     * already paid for. The local record is not touched here — the
     * merchant's confirming event (or the reconciler) is what moves it,
     * so the two clocks cannot disagree.
     */
    public function cancel(Subscription $subscription, bool $immediately): void;

    /**
     * The merchant's current record of one subscription, for the reconciler.
     */
    public function fetchSubscription(string $externalId): RemoteSubscription;

    /**
     * The merchant's invoices for a customer, newest first, for backfill.
     *
     * @return iterable<RemoteInvoice>
     */
    public function fetchInvoices(BillingCustomer $customer): iterable;

    /**
     * Translate one of this merchant's own invoice payloads — the object a
     * webhook carried — into the canonical shape, without fetching anything.
     *
     * @param  array<string, mixed>  $payload
     */
    public function invoiceFromPayload(array $payload): RemoteInvoice;

    /**
     * The merchant's customer id an event concerns — read from the payload
     * when it carries one, fetched when it does not (a Stripe dispute names
     * only a charge). Null when the event is about nobody in particular.
     */
    public function customerExternalId(NormalisedEvent $event): ?string;

    /**
     * Verify a webhook delivery's signature against the raw body and
     * translate it into the canonical event vocabulary. Throws
     * UnsupportedMerchantException when the signature does not hold —
     * the caller answers 4xx and records nothing.
     */
    public function verifySignature(Request $request): NormalisedEvent;
}
