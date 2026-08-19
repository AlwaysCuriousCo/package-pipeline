# Ecommerce & subscription layer for Package Pipeline

## Context

Package Pipeline serves private Composer packages, and access is decided by
*grants*: rows in `package_user` / `repository_user` / `package_team` /
`repository_team` that `Package::scopeVisibleToUser()` folds into every read on
the Composer surface, the API, the panel and the exports. Today those grants can
only be created by an administrator. There is no way to sell access.

The goal is a commercial layer where a **plan** is the configuration object: it
names what it grants, how it is billed, what happens when it lapses, and how
many tokens it permits. A subscription to a plan projects into the existing
grant system, so nothing downstream of a grant has to learn what billing is.
Stripe is the shipped merchant, but the merchant is a driver behind a contract —
mirroring how `SourceProvider` + `RepositoryClient` + `StubClient` already make
GitHub and GitLab interchangeable.

Decisions taken (see the record at the end):

| Decision | Choice |
| --- | --- |
| Unit of entitlement | Plans; a plan grants repositories and/or packages |
| Customer of record | Polymorphic — a `User` or a `Team` |
| Billing models | Recurring, one-time + updates window, trials, coupons |
| Lapse behaviour | Configurable **per plan** |
| Checkout & card management | Provider-hosted now, contract shaped for embedded later |
| Pricing catalog | Local DB is truth, mirrored out to the merchant |
| Tokens | Auto-issue one on activation, more up to the plan's seat cap |
| Self-serve | Public pricing page, public signup + checkout, customer area outside `/admin` |
| Subscription state | Merchant is truth, mirrored by webhook, repaired by a nightly reconcile |
| Dunning | Merchant retries; access continues until it gives up |
| Tax & invoices | Stripe Tax, invoices mirrored locally, VAT/business details collected |
| Updates window | Pin a per-package **version ceiling** at lapse |
| Metadata cache | Bucket by ceiling (fold the ceiling into the existing ETag) |
| Immediate access loss | Dispute, full refund, admin suspension, and customer cancellation |
| Merchants | Stripe (built) and Manual/offline (built) |

---

## The central design choice: entitlements project into the existing pivots

A subscription does **not** get a new branch in `Package::scopeVisibleTo()`. The
Composer metadata path is the hottest query in the app and its three-branch
shape is deliberate. Instead:

1. Add a `source` column (`manual` | `subscription`, default `manual`) to the
   four grant pivots, and widen each composite unique index to include it. The
   docblock on `User::packageGrants()` ([User.php:126](app/Models/User.php#L126))
   already states that duplicate ids in the union are harmless — the caller only
   asks whether an id is in the list — so a package granted both manually and by
   subscription needs no special handling.
2. An **entitlement projector** owns every `source = 'subscription'` row and
   never touches a `manual` one. Administrators keep full control of manual
   grants; the projector cannot clobber them and they cannot silently revoke a
   paid one.
3. `scopeVisibleTo`, `scopeVisibleToUser`, `packageGrants()`,
   `repositoryGrants()`, the Effective access screen and `LogsGrantChanges`
   audit logging all keep working **unchanged**. Zero hot-path cost.

A separate `entitlements` ledger records what each subscription granted and the
version ceiling pinned on it. The pivots answer *who can see what*; the ledger
answers *which subscription granted it and up to which version*. Only the
metadata and dist paths consult the ledger, and only for a package reached
through a subscription.

---

## Schema

New migrations in `database/migrations/`, following the house style (prose
comment per table explaining why it is shaped that way).

**`billing_customers`** — `morphs('billable')` (`User` or `Team`), `name`,
`email`, `company_name`, `tax_id`, `address` (json), `merchant`,
`merchant_customer_id` (nullable — a Manual customer has none), timestamps,
soft deletes. Unique on `(merchant, merchant_customer_id)`.

**`plans`** — `name`, `slug` (unique), `description`, `active`, `listed`
(shown on the public pricing page), `billing_model`
(`recurring` | `one_time_with_updates`), `trial_days`, `token_limit` (nullable =
unlimited), `seat_limit`, `updates_window_months`, `lapse_behaviour`
(`withdraw_entitlement` | `revoke_tokens` | `freeze_at_version` | `none`),
`grace_days` (nullable = follow the merchant), `cancellation`
(`immediate` | `end_of_period`), `auto_issue_token`, `sort`, timestamps,
soft deletes.

**`plan_prices`** — `plan_id`, `currency` (ISO 4217), `amount` (integer minor
units — never a float), `interval` (`month` | `year` | `one_time`),
`interval_count`, `active`, `default`, timestamps.

**`plan_entitlements`** — `plan_id`, `morphs('grantable')` (`Repository` or
`Package`), unique on `(plan_id, grantable_type, grantable_id)`. Deliberately
the same shape as the existing grant pivots.

**`merchant_references`** — `morphs('referenceable')` (`Plan`, `PlanPrice`,
`BillingCustomer`, `Subscription`, `Invoice`), `merchant`, `external_id`,
`synced_at`. Unique on `(referenceable_type, referenceable_id, merchant)` and on
`(merchant, external_id)`. This is what makes the local catalog portable — a
second merchant is a second row, not a second schema.

**`subscriptions`** — `billing_customer_id`, `plan_id`, `plan_price_id`,
`merchant`, `status`, `quantity`, `trial_ends_at`, `current_period_start`,
`current_period_end`, `grace_ends_at`, `cancel_at`, `canceled_at`, `ends_at`,
`suspended_at`, `suspension_reason`, `coupon_code`, timestamps, soft deletes.
Indexed on `(status, current_period_end)` for the reconciler.

**`entitlements`** — `subscription_id`, `billing_customer_id`,
`morphs('grantable')`, `version_ceiling` (nullable, normalised), `active`,
`starts_at`, `ends_at`. The projector's ledger.

**`invoices`** — `billing_customer_id`, nullable `subscription_id`, `merchant`,
`number`, `currency`, `subtotal`, `tax`, `total`, `amount_refunded`, `status`,
`hosted_url`, `pdf_url`, `issued_at`, `paid_at`, `refunded_at`.

**`merchant_events`** — `merchant`, `external_id` (**unique** — the idempotency
key), `type`, `payload` (json), `received_at`, `processed_at`, `failed_at`,
`error`. Prunable via `model:prune`, added to the existing 03:30 schedule entry.

**Alterations**
- `access_tokens`: nullable `subscription_id` (`nullOnDelete`), so the
  `revoke_tokens` lapse behaviour knows exactly which credentials it issued.
- `package_user`, `repository_user`, `package_team`, `repository_team`: add
  `source` string default `'manual'`, drop and recreate the composite unique to
  include it.

---

## Enums (`app/Enums/`)

All backed string enums implementing Filament's `HasLabel`/`HasDescription`,
with behaviour on the enum via `match ($this)` — the established convention.

- `MerchantProvider` — `Stripe`, `Manual`. Carries `getLabel()`,
  `supportsHostedCheckout()`, `supportsPortal()`, `supportsTax()`, and the
  `client()` factory.
- `SubscriptionStatus` — `Incomplete`, `Trialing`, `Active`, `PastDue`,
  `Paused`, `Canceled`, `Unpaid`, `Suspended`, `Expired`. The single method that
  matters is `grantsAccess(): bool` (true for `Trialing`, `Active`, `PastDue`).
  Every driver normalises its own vocabulary into this.
- `BillingModel`, `LapseBehaviour`, `BillingInterval`, `CancellationTiming`.

---

## The merchant driver contract (`app/Merchants/`)

Modelled directly on `app/Sources/` — an interface, `final readonly` value
objects, per-driver implementations, and a throwing stub. **Not** Laravel
Cashier: Cashier owns the `users` table shape, its own subscription models and
its own routes, all of which would fight a provider-neutral contract. Use
`stripe/stripe-php` directly.

```
app/Merchants/
  MerchantClient.php              interface
  UnsupportedMerchantException.php
  StubClient.php                  throws from every method
  Stripe/StripeClient.php
  Manual/ManualClient.php
  Values/{CheckoutRequest,CheckoutSession,RemoteSubscription,RemoteInvoice,NormalisedEvent}.php
```

`MerchantClient` methods:

| Method | Purpose |
| --- | --- |
| `syncProduct(Plan): string` / `syncPrice(PlanPrice): string` | Push the local catalog out, return the external id for `merchant_references` |
| `ensureCustomer(BillingCustomer): string` | Create or update the remote customer, incl. tax id and address |
| `startCheckout(CheckoutRequest): CheckoutSession` | Returns a redirect URL today; the request/response pair is where an embedded flow will later add a client secret without reshaping the contract |
| `portalUrl(BillingCustomer, string $returnUrl): string` | Card management, cancellation, invoice history |
| `changePrice(Subscription, PlanPrice): void` | Upgrade/downgrade |
| `cancel(Subscription, bool $immediately): void` | |
| `fetchSubscription(string $externalId): RemoteSubscription` | Used by the reconciler |
| `fetchInvoices(BillingCustomer): iterable<RemoteInvoice>` | Backfill |
| `verifySignature(Request): NormalisedEvent` | Signature check + normalise into the canonical vocabulary |

`ManualClient` implements the whole interface without any network: `startCheckout`
returns a URL into the admin panel, `portalUrl` throws a soft "not available",
`verifySignature` is never reached. Building it alongside Stripe is what proves
the abstraction is real rather than aspirational.

Factory, mirroring `Package::client()` at [Package.php:611](app/Models/Package.php#L611):

```php
public function client(): MerchantClient
{
    return match ($this) {
        self::Stripe => new StripeClient(config('services.stripe')),
        self::Manual => new ManualClient,
    };
}
```

Credentials go in `config/services.php` (`stripe.secret`, `stripe.publishable`,
`stripe.webhook_secret`, `stripe.tax_enabled`), keeping secrets where the other
integrations put theirs.

---

## Services (`app/Services/Billing/`)

Flat single-purpose classes, matching how `app/Services` is already organised.

- **`EntitlementProjector`** — the heart. `project(Subscription)` computes the
  desired `source = 'subscription'` pivot rows and ledger entries from the
  subscription's status, plan and lapse behaviour, then syncs them
  transactionally. Idempotent by construction: it is safe to run on every event
  and on every reconcile. Handles all four `LapseBehaviour` cases, including
  pinning ceilings for `freeze_at_version`.
- **`SubscriptionProjector`** — applies a `NormalisedEvent` (or a
  `RemoteSubscription` from the reconciler) to the local `Subscription` row, then
  calls `EntitlementProjector`. Guards against out-of-order events by ignoring
  any payload older than the row's last applied event.
- **`CatalogSync`** — pushes plans/prices to a merchant and records
  `merchant_references`. Exposed as `billing:sync-catalog` and a Filament action.
- **`CheckoutBuilder`** — turns a plan + price + customer + optional coupon into
  a `CheckoutRequest`, resolving success/cancel URLs.
- **`VersionCeiling`** — computes the highest normalised version of a package at
  an instant, and answers `permits(PlanVersion, ?string $ceiling)`. Uses
  `composer/semver` (already a dependency) and the existing
  `app/Support/VersionNormalizer.php`.
- **`SubscriptionTokens`** — issues the activation token via the existing
  `Token::issue()`, enforces the plan's `token_limit`, and revokes
  subscription-issued tokens on lapse when the plan says so.

---

## Enforcing the updates window

Three touch points, all in
[ComposerRepositoryController.php](app/Http/Controllers/ComposerRepositoryController.php):

1. **`etag()` (~line 813)** — fold the requesting credential's ceiling for this
   package into the fingerprint. Because `payload()` (~line 697) already keys its
   cache as `composer:metadata:{id}:{dev}:{etag}`, bucketing falls out for free:
   every customer without a ceiling shares today's single cached body, and each
   distinct ceiling gets its own entry. No new cache mechanism.
2. **`payload()`** — drop versions above the ceiling when one is present.
3. **`dist()` (~line 917)** — reject a reference above the ceiling with a 403
   that names the reason, so a stale lock file gets a comprehensible error rather
   than a bare denial.

`lastModified()` already refuses to go backwards, which is what keeps a ceiling
from producing a `Last-Modified` that confuses caches.

The ceiling lookup is one indexed query against `entitlements`, executed only
when the package was reached through a subscription. Resolve it once per request
and stash it on the request attributes alongside `composerToken`, the way
`ResolveComposerRepository` already stashes `composerRepository`.

---

## Webhooks & reconciliation

- Route `POST /billing/{merchant}/webhook`, registered in `routes/web.php`
  outside the `web` group (no session, no CSRF), under a new
  `throttle:merchant-webhooks` limiter defined beside the others in
  `AppServiceProvider::defineRateLimiters()`.
- `MerchantWebhookController` verifies the signature against the **raw body**
  with `hash_equals`, exactly as
  [GitHubWebhookController](app/Http/Controllers/GitHubWebhookController.php)
  does, writes a `merchant_events` row (unique `external_id` swallows replays),
  returns 2xx immediately, and dispatches `ProcessMerchantEvent`.
- `app/Jobs/ProcessMerchantEvent.php` — follows the `DeliverWebhook`
  conventions: explicit `$tries`, `$timeout`, `$deleteWhenMissingModels`,
  `backoff()`, and a `failed()` hook that marks the row and notifies admins.
- `app/Console/Commands/ReconcileBilling.php` (`billing:reconcile`) re-pulls every
  subscription that is active, in grace, or changed in the last 48h, and repairs
  drift. Added to `routes/console.php` at ~04:00 with `->onOneServer()
  ->withoutOverlapping()`, matching every other entry there.

---

## Public surface

Everything anonymous uses the existing non-Filament Blade front end
(`resources/views/components/page-layout.blade.php`), so it inherits the
canonical/OG/JSON-LD handling and the Tailwind build already in place.

- `app/Http/Controllers/Pages/PricingController.php` — `/pricing` and
  `/pricing/{plan:slug}`, under `throttle:pages`. Listed plans only, with the
  packages each unlocks linked to their existing public package pages.
- **Registration** — `/register`, gated by `config('registry.billing.public_signup')`,
  creating a `User` with **no role**. `User::canAccessPanel()` returns
  `$this->roles()->exists()` ([User.php:50](app/Models/User.php#L50)), so a
  customer is structurally incapable of reaching `/admin`. Email verification,
  a honeypot, and `throttle` on the POST.
- **Customer area** — `/billing/*` behind `auth`, in
  `app/Http/Controllers/Billing/`: subscription status and next charge, plan
  change, redirect to the merchant portal for card management, locally-mirrored
  invoice list, and token management capped at the plan's `token_limit`.
- Checkout return lands on `/billing/welcome`, which shows the auto-issued token
  **once** with the two `composer config` lines already used elsewhere in the app.

---

## Filament (`app/Filament/Resources/`)

Auto-discovered; each follows the `Resource + Schemas/ + Tables/ + Pages/`
layout, in a new `'Billing'` navigation group.

- `Plans/` — plan settings, a repeater for prices, and the entitlement pickers.
  The two pickers must be scoped with `visibleToUser(auth()->user())`, the same
  way [TeamForm.php:85](app/Filament/Resources/Teams/Schemas/TeamForm.php#L85)
  scopes its grant pickers.
- `BillingCustomers/`, `Subscriptions/`, `Invoices/`.
- Actions: comp a subscription (Manual driver), suspend/unsuspend, cancel,
  resync catalog, replay a failed merchant event.
- `app/Filament/Widgets/BillingTotals.php` — MRR, active subscriptions, trials
  ending, failed payments. Dropping the file in registers it.
- Policies in Shield's shape in `app/Policies/`, then `php artisan shield:generate`.
- New `WebhookEvent` cases so the existing outgoing-webhook system fires on
  subscription lifecycle changes.

---

## Notifications

Extending the existing `AnnouncedByMail` + `RoutedByAdminNotifier` concerns:
`SubscriptionActivated` (carries the token), `TrialEnding`, `PaymentFailed`,
`SubscriptionLapsed`, `SubscriptionCanceled`, `InvoicePaid`. Admin-side alerts
(dispute opened, reconcile drift, webhook processing failure) go through
`app/Services/AdminNotifier.php`.

---

## Config

A `billing` block in `config/registry.php`, env-driven with an explicit cast and
a prose rationale per key — the file's established style:
`BILLING_ENABLED`, `BILLING_MERCHANT`, `BILLING_PUBLIC_SIGNUP`,
`BILLING_CURRENCY`, `BILLING_GRACE_DAYS`, `BILLING_TERMS_URL`,
`BILLING_RECONCILE_LOOKBACK_HOURS`. Secrets stay in `config/services.php`.

---

## Build order

Each step leaves the app working and testable; all of them ship together.

1. Schema, enums, models, factories, `source` column on the four pivots.
2. `EntitlementProjector` + `ManualClient` — full lifecycle end to end with no
   network calls at all. This is the step that must be watertight.
3. Filament resources, policies, widget, admin actions.
4. `StripeClient`, `CatalogSync`, hosted checkout + portal, webhook ingestion,
   `ProcessMerchantEvent`, invoice mirroring, `billing:reconcile`.
5. Public pricing page, registration, customer area, token issuance and caps.
6. Version ceilings: `VersionCeiling` service, the three controller touch points,
   the `freeze_at_version` lapse path.
7. `docs/billing.md` and `docs/merchant-drivers.md`, in the style of the existing
   docs; README section; `.env.example` keys.

---

## Verification

- `composer test` — new Feature tests alongside the existing ones (PHPUnit,
  `RefreshDatabase`, `$seed = true`, `Http::preventStrayRequests()`):
  - `BillingEntitlementScopingTest` — a subscription makes packages visible over
    `/p2` and `/dist`; lapse withdraws them; a manual grant on the same package
    survives the lapse.
  - `SubscriptionLifecycleTest` — every status transition, all four lapse
    behaviours, the four immediate-withdrawal events.
  - `MerchantWebhookTest` — signature rejection, replayed `external_id` is a
    no-op, out-of-order events do not regress state.
  - `VersionCeilingMetadataTest` — a ceilinged client sees a truncated version
    list and a distinct ETag; an unceilinged client's response and cache entry
    are byte-identical to today's.
  - `CheckoutFlowTest`, `PublicSignupTest`, `CustomerAreaTest`,
    `SubscriptionTokenLimitTest`.
  - A `FakeMerchantClient` bound in the container for anything that would
    otherwise call Stripe.
- `composer lint` and `composer analyse` must both pass.
- Manual: `composer run dev`, seed a plan, comp a Manual subscription to a test
  user, confirm `composer require` against the registry succeeds with the
  auto-issued token and 403s after suspending the subscription.
- Stripe: test-mode keys, `stripe listen --forward-to localhost:8000/billing/stripe/webhook`,
  walk a full purchase → renewal → failed payment → cancellation.

---

## Flagged for your call, not assumed

- **Immediate loss of access on customer cancellation** is unusual — the norm is
  that a cancelled subscription runs to the end of the period already paid for,
  and doing otherwise tends to produce refund requests. It is implemented as the
  per-plan `cancellation` setting, defaulting to `immediate` as you chose, so it
  can be changed per plan without a deploy.
- **Upgrade/downgrade timing** was not asked about; the plan assumes the
  industry default — upgrades apply immediately with proration, downgrades at
  period end. Say if you want otherwise.
- **Team subscriptions need a billing contact.** `Team` today has no owner and no
  per-team roles ([docs/teams.md](docs/teams.md) is explicit that this is
  deliberate). A `Team` billing customer therefore needs one nominated user; this
  plan adds that as a nullable `billing_contact_user_id`, which is the smallest
  change that does not import a team-roles concept.
- **Usage-based billing is out of scope.** `downloads` carries only
  `token_prefix` as credential attribution, and the two non-Composer archive
  paths write `null`. Metering per subscriber would need a schema change and a
  revisit of the 400-day `downloads:prune` retention.
- **Stripe Tax is the most merchant-specific part of the design.** It sits behind
  `MerchantClient::supportsTax()`; a merchant without it would need the tax
  columns filled some other way.
