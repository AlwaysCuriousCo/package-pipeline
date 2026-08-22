<?php

namespace App\Jobs;

use App\Enums\SubscriptionStatus;
use App\Merchants\Values\NormalisedEvent;
use App\Merchants\Values\RemoteInvoice;
use App\Models\BillingCustomer;
use App\Models\Invoice;
use App\Models\MerchantEvent;
use App\Models\MerchantReference;
use App\Models\PlanPrice;
use App\Models\Subscription;
use App\Notifications\Billing\BillingAlert;
use App\Notifications\Billing\PaymentFailed;
use App\Notifications\Billing\SubscriptionActivated;
use App\Notifications\Billing\SubscriptionLapsed;
use App\Services\AdminNotifier;
use App\Services\Billing\EntitlementProjector;
use App\Services\Billing\SubscriptionProjector;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Acts on one verified merchant event, off the request path.
 *
 * The recorded merchant_events row is re-read and marked processed or failed,
 * so the inbox is also the ledger of what has actually been acted on — a
 * failed row plus its error is the queue of work still owed, and the panel's
 * replay action re-dispatches exactly this job.
 *
 * Subscription state is never taken from the payload. The event is a doorbell:
 * it says which subscription moved, and the current truth is fetched fresh
 * from the merchant — which makes out-of-order deliveries harmless twice over
 * (the fetch is current, and SubscriptionProjector discards stale moments).
 */
class ProcessMerchantEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly int $eventId,
        public readonly string $kind,
        public readonly CarbonImmutable $occurredAt,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(): void
    {
        $event = MerchantEvent::query()->find($this->eventId);

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        try {
            match ($this->kind) {
                NormalisedEvent::CHECKOUT_COMPLETED => $this->checkoutCompleted($event),
                NormalisedEvent::SUBSCRIPTION_CHANGED => $this->subscriptionChanged($event),
                NormalisedEvent::INVOICE_PAID => $this->invoicePaid($event),
                NormalisedEvent::INVOICE_PAYMENT_FAILED => $this->invoicePaymentFailed($event),
                NormalisedEvent::INVOICE_REFUNDED => $this->invoiceRefunded($event),
                NormalisedEvent::DISPUTE_OPENED => $this->disputeOpened($event),
                default => null,
            };

            $event->markProcessed();
        } catch (Throwable $e) {
            $event->markFailed($e->getMessage());

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $event = MerchantEvent::query()->find($this->eventId);

        if ($event === null) {
            return;
        }

        app(AdminNotifier::class)->send(new BillingAlert(
            'A merchant event failed processing',
            "{$event->merchant->getLabel()} event {$event->type} ({$event->external_id}) gave up after retries: ".($exception?->getMessage() ?? 'unknown error'),
            url('/admin'),
        ));
    }

    /**
     * A checkout finished. Create (or find) the local subscription the
     * payment produced; the activation token is minted by the welcome page,
     * which is the only place the buyer can be shown it.
     */
    private function checkoutCompleted(MerchantEvent $event): void
    {
        $payload = $event->payload;

        $customer = $this->customerFrom($payload['customer'] ?? null);
        $price = $this->priceFrom($payload['metadata']['plan_price_id'] ?? null);

        if ($customer === null || $price === null) {
            throw new \RuntimeException('Checkout session names no known customer or price.');
        }

        $remoteSubscriptionId = $payload['subscription'] ?? null;

        $subscription = $this->findOrCreateSubscription($event, $customer, $price, $remoteSubscriptionId);

        if (is_string($remoteSubscriptionId)) {
            $remote = $event->merchant->client()->fetchSubscription($remoteSubscriptionId);
            (new SubscriptionProjector)->apply($subscription, $remote);
        } else {
            // A one-time purchase: no remote subscription exists, so the
            // session id can take the row's one reference slot. A
            // subscription checkout keeps that slot for the subscription id
            // every later webhook resolves by; the welcome page reaches
            // those through the recorded event instead.
            MerchantReference::remember($subscription, $event->merchant, (string) $payload['id']);

            // Active from now; the updates window is the period, and the
            // reconciler expires it when the window closes.
            $months = $price->plan->updates_window_months;

            $subscription->forceFill([
                'status' => SubscriptionStatus::Active,
                'current_period_start' => now(),
                'current_period_end' => $months !== null ? now()->addMonths($months) : null,
                'last_event_at' => now(),
            ])->save();

            (new EntitlementProjector)->project($subscription);
        }

        $customer->contact()?->notify(new SubscriptionActivated($subscription));
    }

    private function subscriptionChanged(MerchantEvent $event): void
    {
        $externalId = (string) ($event->payload['id'] ?? '');

        $local = MerchantReference::resolve($event->merchant, $externalId);

        if (! $local instanceof Subscription) {
            // A subscription this app never sold — created in the merchant's
            // own dashboard, or racing its checkout.completed event, which
            // will create the row and fetch fresh state itself.
            return;
        }

        $wasGranting = $local->grantsAccess();

        $remote = $event->merchant->client()->fetchSubscription($externalId);
        (new SubscriptionProjector)->apply($local, $remote);

        $local->refresh();

        if ($wasGranting && ! $local->grantsAccess()) {
            $local->customer?->contact()?->notify(new SubscriptionLapsed($local));
        }
    }

    private function invoicePaid(MerchantEvent $event): void
    {
        $this->mirrorInvoice($event);
    }

    private function invoicePaymentFailed(MerchantEvent $event): void
    {
        $invoice = $this->mirrorInvoice($event);

        $subscription = $invoice?->subscription;

        if ($subscription !== null) {
            $subscription->customer?->contact()?->notify(new PaymentFailed($subscription));
        }
    }

    /**
     * A full refund withdraws access immediately — the operator's chosen
     * policy. Partial refunds update the mirror and change nothing else.
     */
    private function invoiceRefunded(MerchantEvent $event): void
    {
        $payload = $event->payload;

        $invoiceExternalId = $payload['invoice'] ?? null;

        if (! is_string($invoiceExternalId)) {
            return;
        }

        $invoice = MerchantReference::resolve($event->merchant, $invoiceExternalId);

        if (! $invoice instanceof Invoice) {
            return;
        }

        $invoice->forceFill([
            'amount_refunded' => (int) ($payload['amount_refunded'] ?? $invoice->amount_refunded),
            'refunded_at' => now(),
        ])->save();

        if (! $invoice->fullyRefunded()) {
            return;
        }

        $subscription = $invoice->subscription;

        if ($subscription !== null && $subscription->grantsAccess()) {
            $subscription->forceFill([
                'status' => SubscriptionStatus::Canceled,
                'canceled_at' => now(),
                'ends_at' => now(),
                'grace_ends_at' => null,
                'last_event_at' => now(),
            ])->save();

            (new EntitlementProjector)->project($subscription);

            $subscription->customer?->contact()?->notify(new SubscriptionLapsed($subscription));
        }
    }

    /**
     * A dispute is the customer telling their bank this charge was not
     * legitimate. Access is withdrawn at once — by suspension, which no
     * lapse behaviour softens — and a person is told, because the next steps
     * (evidence, refund, reinstatement) are theirs.
     */
    private function disputeOpened(MerchantEvent $event): void
    {
        $normalised = new NormalisedEvent(
            externalId: $event->external_id,
            type: $event->type,
            kind: $this->kind,
            payload: $event->payload,
            occurredAt: $this->occurredAt,
        );

        $customerExternalId = $event->merchant->client()->customerExternalId($normalised);
        $customer = $this->customerFrom($customerExternalId);

        $projector = new EntitlementProjector;

        if ($customer !== null) {
            foreach ($customer->subscriptions as $subscription) {
                if ($subscription->suspended_at === null) {
                    $subscription->forceFill([
                        'suspended_at' => now(),
                        'suspension_reason' => 'Payment disputed',
                    ])->save();
                }
            }

            $projector->projectCustomer($customer);
        }

        app(AdminNotifier::class)->send(new BillingAlert(
            'A payment was disputed',
            $customer !== null
                ? "{$customer->name} disputed a charge. Their subscriptions are suspended pending review."
                : "A charge was disputed by a customer this registry cannot match (merchant customer: {$customerExternalId}). Review it at the merchant.",
            url('/admin'),
        ));
    }

    private function mirrorInvoice(MerchantEvent $event): ?Invoice
    {
        // The payload is the invoice object; normalise it through the driver
        // so this job never learns Stripe's field names.
        $remote = $event->merchant->client()->invoiceFromPayload($event->payload);

        return $this->writeInvoice($event, $remote);
    }

    private function writeInvoice(MerchantEvent $event, RemoteInvoice $remote): ?Invoice
    {
        $customer = $this->customerFrom($remote->customerExternalId);

        if ($customer === null) {
            return null;
        }

        $subscription = $remote->subscriptionExternalId !== null
            ? MerchantReference::resolve($event->merchant, $remote->subscriptionExternalId)
            : null;

        $existing = MerchantReference::resolve($event->merchant, $remote->externalId);

        $invoice = $existing instanceof Invoice ? $existing : new Invoice;

        $invoice->forceFill([
            'billing_customer_id' => $customer->getKey(),
            'subscription_id' => $subscription instanceof Subscription ? $subscription->getKey() : null,
            'merchant' => $event->merchant,
            'number' => $remote->number,
            'currency' => $remote->currency,
            'subtotal' => $remote->subtotal,
            'tax' => $remote->tax,
            'total' => $remote->total,
            'amount_refunded' => $remote->amountRefunded,
            'status' => $remote->status,
            'hosted_url' => $remote->hostedUrl,
            'pdf_url' => $remote->pdfUrl,
            'issued_at' => $remote->issuedAt,
            'paid_at' => $remote->paidAt,
            'refunded_at' => $remote->refundedAt,
        ])->save();

        MerchantReference::remember($invoice, $event->merchant, $remote->externalId);

        return $invoice;
    }

    private function findOrCreateSubscription(MerchantEvent $event, BillingCustomer $customer, PlanPrice $price, ?string $remoteSubscriptionId): Subscription
    {
        if (is_string($remoteSubscriptionId)) {
            $existing = MerchantReference::resolve($event->merchant, $remoteSubscriptionId);

            if ($existing instanceof Subscription) {
                return $existing;
            }
        }

        $subscription = Subscription::query()->create([
            'billing_customer_id' => $customer->getKey(),
            'plan_id' => $price->plan_id,
            'plan_price_id' => $price->getKey(),
            'merchant' => $event->merchant,
            'status' => SubscriptionStatus::Incomplete,
            'quantity' => 1,
        ]);

        if (is_string($remoteSubscriptionId)) {
            MerchantReference::remember($subscription, $event->merchant, $remoteSubscriptionId);
        }

        return $subscription;
    }

    private function customerFrom(?string $externalId): ?BillingCustomer
    {
        if (! is_string($externalId) || $externalId === '') {
            return null;
        }

        return BillingCustomer::query()
            ->where('merchant_customer_id', $externalId)
            ->first();
    }

    private function priceFrom(mixed $id): ?PlanPrice
    {
        return is_numeric($id) ? PlanPrice::query()->find((int) $id) : null;
    }
}
