# Billing

Sell subscriptions that grant package access — plans, Stripe checkout, a
public pricing page, a customer area, and a Manual merchant for the
subscriptions no processor is behind.

Everything is off until you turn it on. A registry that never sets
`BILLING_ENABLED=true` serves exactly what it always served: no routes
answer, no navigation appears, nothing changes on the Composer surface.

## The model in one paragraph

A **plan** is the configuration object: what it grants (repositories and/or
packages — the same two shapes a grant has always had), its **prices**
(monthly, yearly, or one-time), and every lifecycle rule — trial length,
what lapsing does, how cancellation times out, how many tokens a
subscription may hold. A **subscription** is a customer's purchase of a
plan, mirrored from the merchant. The **entitlement projector** turns an
active subscription into rows in the same grant tables administrators
write by hand — marked `source = subscription`, so neither writer can
touch the other's rows — and everything downstream (the Composer
endpoints, the panel, exports) keeps reading exactly what it always read.
Billing never adds a branch to the visibility scopes.

## Turning it on

```dotenv
BILLING_ENABLED=true
BILLING_MERCHANT=stripe
STRIPE_SECRET=sk_live_...
STRIPE_PUBLISHABLE=pk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

Then, at Stripe, add a webhook endpoint for
`https://your-registry.example/billing/stripe/webhook` subscribed to:

- `checkout.session.completed`
- `customer.subscription.created`, `.updated`, `.deleted`, `.paused`, `.resumed`
- `invoice.paid`, `invoice.payment_failed`
- `charge.refunded`, `charge.dispute.created`

In development, `stripe listen --forward-to localhost:8000/billing/stripe/webhook`
prints the `whsec_...` to use.

Optionally:

```dotenv
BILLING_PUBLIC_SIGNUP=true   # open /register to strangers
BILLING_CURRENCY=usd         # the default currency new prices suggest
BILLING_TERMS_URL=           # linked from the signup form when set
STRIPE_TAX_ENABLED=true      # Stripe Tax at checkout (enable it at Stripe first)
```

## Selling something

1. **Commercial → Plans → New plan.** Name it, price it, and pick what it
   grants. The entitlement pickers are scoped to what you yourself can see,
   exactly as the team grant pickers are.
2. The catalog is local and mirrored out: the first checkout syncs the plan
   to Stripe on its own, or push everything with `php artisan
   billing:sync-catalog`. Editing a plan's entitlements re-projects every
   subscriber on the queue.
3. Mark the plan **Listed** and it appears at `/pricing`; leave it unlisted
   and it is still sellable by direct link (`/pricing/{slug}`) — launch
   offers, negotiated tiers.

A buyer signs up (or in), clicks Subscribe, pays on Stripe's hosted
checkout, and lands on a welcome page that shows their access token
**once**, with the two `composer config` lines beside it. Cards, invoices
and self-service cancellation live on Stripe's Billing Portal, reached from
the customer's own `/billing` page — no card number ever touches this app.

## The lifecycle rules, per plan

| Setting | What it decides |
| --- | --- |
| **Billing model** | Recurring, or one-time with an updates window (the perpetual-licence shape). |
| **Lapse behaviour** | What non-payment does: withdraw access; withdraw and revoke the subscription's tokens; **freeze at version** — everything released while paid stays installable forever, newer releases need renewal; or nothing (sponsorships). |
| **Grace days** | Continued access after the merchant gives up collecting, on top of its own retries (Stripe retries for ~3 weeks and access continues throughout `past_due`). Empty follows the merchant alone. |
| **Cancellation** | Whether the customer's own cancellation cuts access immediately or lets the paid period run out. |
| **Token limit** | How many access tokens a subscription may hold; the auto-issued one counts. |

Four events ignore all of it and withdraw access immediately: a **dispute**
(suspends every subscription of the customer and alerts admins), a **full
refund**, an **admin suspension**, and — when the plan says `immediate` — a
cancellation.

## Version ceilings

When a freeze-at-version subscription lapses (or a one-time purchase's
updates window closes), each granted package is pinned at the highest
version that existed at that moment. A pinned client's `/p2` metadata
contains only versions inside the ceiling, dev branches vanish (a branch is
precisely the ongoing work the licence stopped paying for), and `/dist`
refuses newer references with an error that says why — which is what a
stale lock file deserves, not a bare 403.

Any wider path wins over a ceiling: a manual grant, a live subscription, an
unscoped role, a public repository. And the ceiling folds into the
metadata ETag by value, so uncapped clients keep sharing the single cached
document they always shared, byte for byte.

## Manual subscriptions

**Commercial → Subscriptions → New manual subscription** records a
subscription with no processor behind it — comped accounts, wire
transfers, purchase orders. The projector treats it exactly like a paid
one; the activation token is shown to *you* once, to hand over. "Runs
until" empty means indefinitely; a date makes it lapse there, under the
plan's lapse behaviour, when the nightly reconcile passes it.

## What keeps it honest

- **Webhooks** are verified against the raw body, recorded in
  `merchant_events` before they are acknowledged, and processed on the
  queue. The event id is unique, so a redelivery is an acknowledged no-op.
  Subscription state is never believed from a payload — the event says
  which subscription moved and the truth is fetched fresh.
- **`billing:reconcile`** runs nightly at 04:00: re-pulls every
  merchant-billed subscription the merchant might still move (repairing
  whatever a webhook lost during a deploy), crosses the boundaries nobody
  webhooks about — grace running out, Manual periods ending, updates
  windows closing — and sends the trial-ending warnings.
- **Two writers, one table.** Every grant row carries its author
  (`manual` | `subscription`). Cancelling a subscription cannot revoke a
  grant an administrator made by name; an administrator tidying grants
  cannot silently un-sell something paid for.

## Customers and the panel

A customer is a `User` with no role, which is what keeps them out of
`/admin` entirely — `User::canAccessPanel()` asks for a role. Their whole
surface is `/billing`. A **Team** can be the customer instead (one payer,
every member granted through the team's own pivots); it nominates one
member as billing contact, who receives the billing mail and owns the
tokens.

## What this is not

- **Not usage-based billing.** Downloads are counted, not priced.
- **Not an invoice generator.** Stripe renders invoices; this app mirrors
  them (`invoices` table) so history is browsable without an API call and
  survives a merchant migration.
- **Not license enforcement on the package contents.** What a plan sells is
  access; what the code's license permits is between the maintainer and
  the consumer, as ever (see docs/licensing.md).

## See also

- [docs/merchant-drivers.md](merchant-drivers.md) — adding a merchant other
  than Stripe.
