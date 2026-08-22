# Merchant drivers

Stripe is the shipped payment merchant, but nothing above the driver layer
knows that. Adding Paddle, Lemon Squeezy, PayPal — or an internal billing
system — is implementing one interface.

## The shape

The same pattern as the VCS source drivers (`app/Sources`): one contract,
one implementation per provider, an enum that is the registry of drivers,
and a stub that refuses loudly for cases declared ahead of their
implementations.

```
app/Merchants/
  MerchantClient.php          the contract
  StubClient.php              throws from every method
  Stripe/StripeClient.php     implemented
  Manual/ManualClient.php     implemented — the merchant with nobody behind it
  Values/                     the canonical vocabulary drivers translate into
```

Two working implementations ship on purpose. `ManualClient` proves the
contract holds for a driver with no network at all, which is what keeps it
honest — if your driver can't express something, the gap is real and the
contract needs the conversation, not a Stripe-shaped workaround.

## Adding a driver

1. **Add the enum case** in `app/Enums/MerchantProvider.php` and answer its
   capability methods (`supportsHostedCheckout()`, `supportsPortal()`,
   `supportsTax()`, `receivesWebhooks()`). Point `client()` at your class —
   or at `new StubClient($this)` to land the case before the driver.
2. **Implement `MerchantClient`** (`app/Merchants/MerchantClient.php`).
   The rules that matter:
   - *Translate everything.* Nothing outside your driver may learn your
     merchant's vocabulary: statuses normalise into `SubscriptionStatus`
     (the one question the rest of the app asks is `grantsAccess()`),
     money is integer minor units, timestamps are `CarbonImmutable`, event
     names fold into `NormalisedEvent`'s small kind vocabulary.
   - *The local catalog is the truth.* `syncProduct`/`syncPrice` push it
     out and return external ids; the mapping is remembered in
     `merchant_references`, never in columns.
   - *Verify webhooks against the raw body* in `verifySignature()`, with a
     constant-time comparison, and throw `UnsupportedMerchantException`
     when the signature does not hold.
   - *Refuse honestly.* A method your merchant cannot do throws
     `UnsupportedMerchantException` with a message fit to show a person —
     the Manual driver's portal refusal is the template.
3. **Credentials** go in `config/services.php`, env-driven, like every
   other integration's.
4. **Webhooks** already route: `POST /billing/{merchant}/webhook` resolves
   your enum case by value and calls your `verifySignature()`. Everything
   after that — the inbox row, the idempotency, the queue, the projection —
   is shared machinery in `MerchantWebhookController` and
   `ProcessMerchantEvent`, and none of it is yours to reimplement.

## What you inherit for free

Entitlement projection, lapse behaviours, version ceilings, grace windows,
the reconciler (`fetchSubscription` is all it asks of you), invoice
mirroring (`invoiceFromPayload`/`fetchInvoices`), the customer area, the
panel resources, and the notification set. A driver is a translator, not a
billing system.

## Testing without the network

`tests/Feature/MerchantWebhookTest.php` computes real Stripe signatures in
the test and exercises the actual driver's verification — copy that shape.
For lifecycle behaviour, construct `RemoteSubscription` values by hand and
feed them to `SubscriptionProjector::apply()`, as
`tests/Feature/SubscriptionLifecycleTest.php` does; no driver required.
