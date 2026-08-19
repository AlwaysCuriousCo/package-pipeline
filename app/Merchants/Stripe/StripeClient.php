<?php

namespace App\Merchants\Stripe;

use App\Enums\BillingInterval;
use App\Enums\SubscriptionStatus;
use App\Merchants\MerchantClient;
use App\Merchants\UnsupportedMerchantException;
use App\Merchants\Values\CheckoutRequest;
use App\Merchants\Values\CheckoutSession;
use App\Merchants\Values\NormalisedEvent;
use App\Merchants\Values\RemoteInvoice;
use App\Merchants\Values\RemoteSubscription;
use App\Models\BillingCustomer;
use App\Models\MerchantReference;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient as StripeSdk;
use Stripe\Webhook;

/**
 * The Stripe driver.
 *
 * Hosted surfaces throughout: Checkout for purchases, the Billing Portal for
 * cards, invoices and self-service cancellation. Card data never touches this
 * app, which is what keeps it at PCI SAQ-A.
 *
 * Stripe treats prices as immutable, so syncPrice() archives-and-recreates
 * when an amount changes rather than editing — existing subscriptions keep
 * the price object they were sold under, exactly as the local plan_prices
 * rows keep retired prices for the subscriptions on them.
 *
 * Everything Stripe says is translated here and nowhere else: statuses into
 * SubscriptionStatus, event names into NormalisedEvent kinds, timestamps
 * into CarbonImmutable. If a caller can tell this is Stripe, something in
 * this file has leaked.
 */
class StripeClient implements MerchantClient
{
    public function __construct(
        private readonly StripeSdk $stripe,
        private readonly ?string $webhookSecret,
    ) {}

    /**
     * Built from config/services.php, where every other integration keeps
     * its credentials. Named rather than a constructor default so a test
     * can hand in a mocked SDK.
     */
    public static function fromConfig(): self
    {
        $secret = (string) config('services.stripe.secret');

        if ($secret === '') {
            throw new UnsupportedMerchantException(
                'Stripe is not configured: set STRIPE_SECRET before selling through it.'
            );
        }

        return new self(
            new StripeSdk($secret),
            config('services.stripe.webhook_secret'),
        );
    }

    public function syncProduct(Plan $plan): string
    {
        $existing = MerchantReference::externalId($plan, \App\Enums\MerchantProvider::Stripe);

        $payload = [
            'name' => $plan->name,
            'description' => $plan->description ?: null,
            'active' => $plan->active,
            'metadata' => ['plan_id' => (string) $plan->getKey(), 'plan_slug' => $plan->slug],
        ];

        if ($existing !== null) {
            $this->stripe->products->update($existing, $payload);

            return $existing;
        }

        return $this->stripe->products->create($payload)->id;
    }

    public function syncPrice(PlanPrice $price): string
    {
        $product = $this->syncProduct($price->plan);
        $existing = MerchantReference::externalId($price, \App\Enums\MerchantProvider::Stripe);

        if ($existing !== null) {
            $remote = $this->stripe->prices->retrieve($existing);

            if ($this->matches($remote, $price, $product)) {
                // Only activity can be edited on a price; everything else
                // matching means there is nothing to do but keep it aligned.
                if ($remote->active !== $price->active) {
                    $this->stripe->prices->update($existing, ['active' => $price->active]);
                }

                return $existing;
            }

            // A changed amount is a new price. Archive the old object so the
            // dashboard stops offering it; subscriptions on it are untouched.
            $this->stripe->prices->update($existing, ['active' => false]);
        }

        $payload = [
            'product' => $product,
            'currency' => $price->currency,
            'unit_amount' => $price->amount,
            'active' => $price->active,
            'metadata' => ['plan_price_id' => (string) $price->getKey()],
        ];

        if ($price->interval->recurring()) {
            $payload['recurring'] = [
                'interval' => $price->interval->value,
                'interval_count' => $price->interval_count,
            ];
        }

        return $this->stripe->prices->create($payload)->id;
    }

    public function ensureCustomer(BillingCustomer $customer): string
    {
        $payload = [
            'name' => $customer->company_name ?: $customer->name,
            'email' => $customer->email,
            'metadata' => ['billing_customer_id' => (string) $customer->getKey()],
        ];

        if (is_array($customer->address) && $customer->address !== []) {
            $payload['address'] = array_intersect_key(
                $customer->address,
                array_flip(['line1', 'line2', 'city', 'state', 'postal_code', 'country']),
            );
        }

        if ($customer->merchant_customer_id !== null) {
            $this->stripe->customers->update($customer->merchant_customer_id, $payload);

            return $customer->merchant_customer_id;
        }

        return $this->stripe->customers->create($payload)->id;
    }

    public function startCheckout(CheckoutRequest $request): CheckoutSession
    {
        $customerId = $this->ensureCustomer($request->customer);
        $priceId = $this->syncPrice($request->price);
        $plan = $request->price->plan;

        $payload = [
            'customer' => $customerId,
            'mode' => $request->price->interval->recurring() ? 'subscription' : 'payment',
            'line_items' => [['price' => $priceId, 'quantity' => $request->quantity]],
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
            // Promotion codes are authored in the Stripe dashboard; the
            // checkout page offers the field and validates the code, so this
            // app never keeps a coupon table of its own.
            'allow_promotion_codes' => true,
            'metadata' => [
                'billing_customer_id' => (string) $request->customer->getKey(),
                'plan_id' => (string) $plan->getKey(),
                'plan_price_id' => (string) $request->price->getKey(),
            ],
        ];

        if ($request->collectTax) {
            $payload['automatic_tax'] = ['enabled' => true];
            $payload['customer_update'] = ['address' => 'auto', 'name' => 'auto'];
        }

        if ($request->collectBusinessDetails) {
            $payload['tax_id_collection'] = ['enabled' => true];
        }

        if ($request->price->interval->recurring()) {
            $payload['subscription_data'] = array_filter([
                'trial_period_days' => $plan->trial_days > 0 ? $plan->trial_days : null,
                'metadata' => ['plan_id' => (string) $plan->getKey()],
            ]);
        } else {
            // A one-time purchase still produces an invoice, which is what
            // the mirror and the customer's records hang off.
            $payload['invoice_creation'] = ['enabled' => true];
        }

        $session = $this->stripe->checkout->sessions->create($payload);

        return new CheckoutSession($session->id, $session->url);
    }

    public function portalUrl(BillingCustomer $customer, string $returnUrl): string
    {
        if ($customer->merchant_customer_id === null) {
            throw new UnsupportedMerchantException(
                'This customer has no Stripe record yet, so there is no portal to open.'
            );
        }

        return $this->stripe->billingPortal->sessions->create([
            'customer' => $customer->merchant_customer_id,
            'return_url' => $returnUrl,
        ])->url;
    }

    public function changePrice(Subscription $subscription, PlanPrice $price): void
    {
        $externalId = $this->subscriptionExternalId($subscription);
        $priceId = $this->syncPrice($price);

        $remote = $this->stripe->subscriptions->retrieve($externalId);

        $this->stripe->subscriptions->update($externalId, [
            'items' => [[
                'id' => $remote->items->data[0]->id,
                'price' => $priceId,
                'quantity' => $subscription->quantity,
            ]],
            'proration_behavior' => 'create_prorations',
        ]);
    }

    public function cancel(Subscription $subscription, bool $immediately): void
    {
        $externalId = $this->subscriptionExternalId($subscription);

        if ($immediately) {
            $this->stripe->subscriptions->cancel($externalId);

            return;
        }

        $this->stripe->subscriptions->update($externalId, ['cancel_at_period_end' => true]);
    }

    public function fetchSubscription(string $externalId): RemoteSubscription
    {
        return $this->normaliseSubscription(
            $this->stripe->subscriptions->retrieve($externalId, ['expand' => ['discounts']]),
        );
    }

    public function fetchInvoices(BillingCustomer $customer): iterable
    {
        if ($customer->merchant_customer_id === null) {
            return;
        }

        $invoices = $this->stripe->invoices->all([
            'customer' => $customer->merchant_customer_id,
            'limit' => 100,
        ]);

        foreach ($invoices->autoPagingIterator() as $invoice) {
            yield $this->normaliseInvoice($invoice);
        }
    }

    public function verifySignature(Request $request): NormalisedEvent
    {
        if (! $this->webhookSecret) {
            throw new UnsupportedMerchantException(
                'Stripe webhooks are not configured: set STRIPE_WEBHOOK_SECRET.'
            );
        }

        try {
            // Raw body, exactly as it arrived — the signed bytes are the
            // verified bytes, the same rule the git-provider webhooks follow.
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $this->webhookSecret,
            );
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            throw new UnsupportedMerchantException('Stripe webhook rejected: '.$e->getMessage(), previous: $e);
        }

        return new NormalisedEvent(
            externalId: $event->id,
            type: $event->type,
            kind: $this->kindOf($event->type),
            payload: $event->data->object->toArray(),
            occurredAt: CarbonImmutable::createFromTimestamp($event->created),
        );
    }

    public function invoiceFromPayload(array $payload): RemoteInvoice
    {
        return $this->normaliseInvoice(json_decode((string) json_encode($payload)));
    }

    /**
     * Most Stripe payloads name their customer; a dispute names only its
     * charge, and the charge knows the customer.
     */
    public function customerExternalId(NormalisedEvent $event): ?string
    {
        $customer = $event->payload['customer'] ?? null;

        if (is_string($customer) && $customer !== '') {
            return $customer;
        }

        $charge = $event->payload['charge'] ?? null;

        if ($event->kind === NormalisedEvent::DISPUTE_OPENED && is_string($charge)) {
            $customer = $this->stripe->charges->retrieve($charge)->customer;

            return is_string($customer) ? $customer : null;
        }

        return null;
    }

    /**
     * Stripe's event names, folded into the small vocabulary the processor
     * switches on. Everything unlisted is verified, recorded and ignored —
     * which is the honest treatment of an event nobody asked to act on.
     */
    private function kindOf(string $type): string
    {
        return match ($type) {
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
            'customer.subscription.paused',
            'customer.subscription.resumed' => NormalisedEvent::SUBSCRIPTION_CHANGED,
            'invoice.paid' => NormalisedEvent::INVOICE_PAID,
            'invoice.payment_failed' => NormalisedEvent::INVOICE_PAYMENT_FAILED,
            'charge.refunded' => NormalisedEvent::INVOICE_REFUNDED,
            'charge.dispute.created' => NormalisedEvent::DISPUTE_OPENED,
            'checkout.session.completed' => NormalisedEvent::CHECKOUT_COMPLETED,
            default => NormalisedEvent::IGNORE,
        };
    }

    /**
     * @param  \Stripe\Subscription  $remote
     */
    public function normaliseSubscription(object $remote): RemoteSubscription
    {
        $item = $remote->items->data[0] ?? null;

        // Newer Stripe API versions carry the period on the item; older ones
        // on the subscription. Read whichever this account's version filled.
        $periodStart = $item->current_period_start ?? $remote->current_period_start ?? null;
        $periodEnd = $item->current_period_end ?? $remote->current_period_end ?? null;

        return new RemoteSubscription(
            externalId: $remote->id,
            customerExternalId: (string) $remote->customer,
            status: $this->status($remote->status),
            priceExternalId: $item?->price?->id,
            quantity: (int) ($item->quantity ?? 1),
            trialEndsAt: $this->time($remote->trial_end ?? null),
            currentPeriodStart: $this->time($periodStart),
            currentPeriodEnd: $this->time($periodEnd),
            cancelAt: $this->time($remote->cancel_at ?? null),
            canceledAt: $this->time($remote->canceled_at ?? null),
            endedAt: $this->time($remote->ended_at ?? null),
            couponCode: $remote->discounts[0]->coupon->id ?? null,
            asOf: CarbonImmutable::now(),
        );
    }

    /**
     * @param  \Stripe\Invoice  $remote
     */
    public function normaliseInvoice(object $remote): RemoteInvoice
    {
        return new RemoteInvoice(
            externalId: $remote->id,
            customerExternalId: (string) $remote->customer,
            subscriptionExternalId: $this->invoiceSubscriptionId($remote),
            number: $remote->number ?? null,
            currency: $remote->currency,
            subtotal: (int) $remote->subtotal,
            tax: (int) ($remote->tax ?? 0),
            total: (int) $remote->total,
            amountRefunded: (int) ($remote->amount_refunded ?? 0),
            status: (string) $remote->status,
            hostedUrl: $remote->hosted_invoice_url ?? null,
            pdfUrl: $remote->invoice_pdf ?? null,
            issuedAt: $this->time($remote->created ?? null),
            paidAt: $this->time($remote->status_transitions->paid_at ?? null),
            refundedAt: null,
        );
    }

    /**
     * The subscription an invoice belongs to. Newer API versions moved it
     * from a top-level field into the line items' parent details.
     */
    private function invoiceSubscriptionId(object $remote): ?string
    {
        if (isset($remote->subscription) && $remote->subscription !== null) {
            return (string) $remote->subscription;
        }

        foreach ($remote->lines->data ?? [] as $line) {
            $id = $line->parent?->subscription_item_details?->subscription ?? null;

            if ($id !== null) {
                return (string) $id;
            }
        }

        return null;
    }

    private function status(string $stripe): SubscriptionStatus
    {
        return match ($stripe) {
            'trialing' => SubscriptionStatus::Trialing,
            'active' => SubscriptionStatus::Active,
            'past_due' => SubscriptionStatus::PastDue,
            'paused' => SubscriptionStatus::Paused,
            'canceled' => SubscriptionStatus::Canceled,
            'unpaid' => SubscriptionStatus::Unpaid,
            'incomplete' => SubscriptionStatus::Incomplete,
            'incomplete_expired' => SubscriptionStatus::Expired,
            default => SubscriptionStatus::Incomplete,
        };
    }

    private function time(?int $timestamp): ?CarbonImmutable
    {
        return $timestamp !== null ? CarbonImmutable::createFromTimestamp($timestamp) : null;
    }

    /**
     * @param  \Stripe\Price  $remote
     */
    private function matches(object $remote, PlanPrice $price, string $product): bool
    {
        $intervalMatches = $price->interval === BillingInterval::OneTime
            ? $remote->recurring === null
            : $remote->recurring !== null
                && $remote->recurring->interval === $price->interval->value
                && (int) $remote->recurring->interval_count === $price->interval_count;

        return $remote->currency === $price->currency
            && (int) $remote->unit_amount === $price->amount
            && (string) $remote->product === $product
            && $intervalMatches;
    }

    private function subscriptionExternalId(Subscription $subscription): string
    {
        $id = MerchantReference::externalId($subscription, \App\Enums\MerchantProvider::Stripe);

        if ($id === null) {
            throw new UnsupportedMerchantException(
                'This subscription has no Stripe record: it was never sold there.'
            );
        }

        return $id;
    }
}
