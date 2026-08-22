# Over-engineering audit — cut list

## Context

A repo-wide audit (2026-08-22, branch `feat/enhance`) hunting only for
over-engineering: dead code, unreachable branches, speculative flexibility,
duplication, and hand-rolled stdlib. Correctness, security and performance were
explicitly out of scope. Every finding below was verified by grepping usage
across `app/ tests/ routes/ config/ database/ resources/` (vendor excluded);
the evidence column records what the grep proved.

Estimated total: **~1,250 lines and 1 composer dependency** removable.

Nothing here is a behaviour change for a user. The riskiest items are the two
interface-method deletions (batch 2) and the notification/controller dedups
(batch 5), which touch money paths — those have test coverage
(`MerchantWebhookTest`, `SubscriptionLifecycleTest`, the archive-serving
feature tests) that must stay green.

### How to work this plan

Each batch is independently shippable. Within a batch, items are ranked
biggest cut first. After each batch:

```sh
composer lint && composer analyse && composer test
```

PHPStan is the real tripwire here — most of these are "no references" deletes,
and level-max Larastan will flag anything a delete orphans.

---

## Batch 1 — pure deletes, zero call sites

Nothing references these. Delete and run the suite.

| # | Cut | Path | Evidence | ~Lines |
|---|-----|------|----------|-------:|
| 1.1 | `App\Merchants\StubClient` (whole file) | `app/Merchants/StubClient.php` | Zero references repo-wide. `MerchantProvider::client()` is an exhaustive match on `Stripe\|Manual` with no default arm. Only mentions are prose in `docs/merchant-drivers.md` and `docs/plans/ecommerce-subscriptions.md` — update both (see batch 7). | 97 |
| 1.2 | `HidesBreadcrumbs` trait + its 13 `use` lines and imports | `app/Filament/Concerns/HidesBreadcrumbs.php` | `AdminPanelProvider` already calls `->breadcrumbs(false)`; the vendor blade checks `filament()->hasBreadcrumbs()` *before* calling `getBreadcrumbs()`, so every override is a no-op. | 35 |
| 1.3 | `use HandlesAuthorization;` in all 15 policies | `app/Policies/*.php` | `allow(`/`deny(` — zero hits; every policy method returns a bare bool. | 30 |
| 1.4 | `BillingCustomer::beneficiaries()` | `app/Models/BillingCustomer.php:621` | Zero call sites. Its own docblock names `EntitlementProjector` as the caller, but `syncPivots()` reads `$customer->billable` and branches on `User\|Team` itself. | 22 |
| 1.5 | Dead enum helpers: `LapseBehaviour::keepsGrants()`, `GrantSource::editableByHand()`, `MerchantProvider::supportsHostedCheckout()`, `BillingModel::renews()`, `BillingModel::hasUpdatesWindow()` | `app/Enums/` | Zero call sites each. All the code that could use them matches on the enum cases directly (`EntitlementProjector::syncLedger`, `ReconcileBilling:112`, `CheckoutController` via `purchasable()`). | 54 |
| 1.6 | `LicenseUsage::label()` | `app/Support/LicenseUsage.php` | No prod caller (one test-only use). Both display sites (`Licenses.php:84`, `licenses.blade.php:52`) hardcode `?? 'Not declared'`. Delete the test assertion with it. | 6 |
| 1.7 | `inspire` Artisan closure | `routes/console.php:10` | Untouched Laravel skeleton stub; no schedule entry, no docs. | 5 |
| 1.8 | Empty JS entrypoint + its vite input | `resources/js/app.js`, `vite.config.js` | File body is literally `//`. The only `@vite` call in the repo is `@vite('resources/css/app.css')` in `page-layout.blade.php`. | 2 |
| 1.9 | Explicit `->pages([Dashboard::class])` + import | `app/Providers/Filament/AdminPanelProvider.php` | `discoverPages(in: app_path('Filament/Pages'))` on the next line already registers it; Filament de-dupes but the line is noise. | 4 |
| 1.10 | Unused `use App\Sources\StubClient;` import | `app/Enums/MerchantProvider.php:8` | Imported only for a `@see` docblock line. (Moot once batch 3 lands.) | 1 |

**Batch total: ~256 lines**

## Batch 2 — dead merchant-contract surface

The `MerchantClient` interface carries methods nothing calls. Shrink the
contract, then the implementations. Money path — run `MerchantWebhookTest`,
`SubscriptionLifecycleTest`, `SignupTest` after.

| # | Cut | Path | Evidence | ~Lines |
|---|-----|------|----------|-------:|
| 2.1 | `changePrice()` from the interface and all implementations | `app/Merchants/MerchantClient.php:69`, `Stripe/StripeClient.php:212`, `Manual/ManualClient.php:66` | Zero call sites. Upgrades/downgrades happen in Stripe's hosted Billing Portal (`BillingController::portal`) — the only price-change path that exists. | 45 |
| 2.2 | `fetchInvoices()` from the interface and all implementations (incl. the Stripe `autoPagingIterator` generator) | `app/Merchants/MerchantClient.php:88`, `StripeClient.php:249`, `ManualClient.php:81` | Zero call sites. Invoices are mirrored only by webhook via `invoiceFromPayload()`. The docblock claims "for backfill", but `ReconcileBilling` has no invoice sweep. | 40 |
| 2.3 | Six structurally unreachable `ManualClient` methods: `ensureCustomer`, `cancel`, `fetchSubscription`, `invoiceFromPayload`, `customerExternalId`, `verifySignature` | `app/Merchants/Manual/ManualClient.php` | Every caller guards Manual out first: `CheckoutBuilder:76` skips `ensureCustomer`; `CancelSubscriptionAction:43` skips `cancel`; `Subscription::scopeReconcilable` excludes Manual from `fetchSubscription`; `MerchantWebhookController:35` 404s (Manual `receivesWebhooks()` is false) before the webhook-only trio. Decide with 2.1/2.2 whether these leave the interface too, or Manual keeps thin `throw` stubs. Note: `cancel()`'s comment names `SubscriptionProjector` as the local canceller — it's actually `CancelSubscriptionAction`. Fix or delete with it. | 45 |
| 2.4 | `customerExternalId(NormalisedEvent $event)` → `customerExternalId(array $payload)`; drop the VO reconstruction in the caller | `app/Jobs/ProcessMerchantEvent.php:245`, `MerchantClient.php:100`, `StripeClient.php:311` | `disputeOpened()` is the sole caller and rebuilds a `NormalisedEvent` from a stored row just so the callee can read two fields (`payload`, `kind`). The `kind === DISPUTE_OPENED` guard inside `StripeClient` is then always-true. | 12 |

**Batch total: ~142 lines**

## Batch 3 — providers that don't exist (Gitea/Bitbucket)

| # | Cut | Path | Evidence | ~Lines |
|---|-----|------|----------|-------:|
| 3.1 | `App\Sources\StubClient` (whole class — 9 methods, all `throw $this->unsupported()`) | `app/Sources/StubClient.php` | Two prod call sites, both `default =>` match arms (`Package.php:1054`, `Source.php:96`). Replace with `default => throw new UnsupportedProviderException(...)` inline. | 70 |
| 3.2 | `SourceProvider::Gitea` and `::Bitbucket` cases + their 8 match arms across `getLabel/defaultApiUrl/host/browseUrl/forHost` | `app/Enums/SourceProvider.php` | No client implements either; every arm routes to the stub. No Gitea/Bitbucket row exists in seeders, factories or tests. One test (`GitLabProviderTest:213`) asserts `forHost()` guesses Gitea from a hostname — delete that assertion with the case. | 25 |

**Caveat:** if a `sources.provider` column value of `gitea`/`bitbucket` could
exist in a deployed database, enum-backed casting would throw on read. Check
before shipping: no seeder or migration writes one, but a cautious deploy adds
a one-line data assertion or leaves the case with a `throw` arm for one
release.

**Batch total: ~95 lines**

## Batch 4 — Shield policy surface

| # | Cut | Path | Evidence | ~Lines |
|---|-----|------|----------|-------:|
| 4.1 | The 68 generated `restore/restoreAny/forceDelete/forceDeleteAny/replicate/reorder` methods across all 14 Shield policies + trim `policies.methods` in config | `app/Policies/*.php`, `config/filament-shield.php:159` | Zero hits for `RestoreAction\|ForceDeleteAction\|TrashedFilter\|ReplicateAction\|reorderable(true)` in app/ + resources/. The 4 SoftDeletes models (Plan, Subscription, BillingCustomer, Token) expose no trashed filter or restore action in any resource. Also removes ~84 dead permission checkboxes from the Roles screen. Re-run `ShieldPermissionSeeder` / prune the orphaned `permissions` rows, and trim `tests/Feature/PolicyPermissionTest.php:44-55`. | 280 |
| 4.2 | `InvoicePolicy::create/update/delete/deleteAny` | `app/Policies/InvoicePolicy.php` | Resource is list-only by design: `canCreate(): false`, only `index` in `getPages()`, no Edit/Delete actions in `InvoicesTable`. | 24 |
| 4.3 | `PackageAdvisoryPolicy::deleteAny` | `app/Policies/PackageAdvisoryPolicy.php:64` | `AdvisoriesRelationManager` has no bulk actions — imports Create/Delete/Edit actions only. | 5 |

**Batch total: ~309 lines**

## Batch 5 — duplication (shrink, careful diffs)

Same logic, fewer copies. These change shared code on live paths — lean on the
existing feature tests.

| # | Cut | Path | Evidence | ~Lines |
|---|-----|------|----------|-------:|
| 5.1 | Five copies of the same `toDatabase()` FilamentNotification builder + five near-identical `toSlack()` bodies | `app/Notifications/PackageAbandoned.php`, `PackageVersionsPublished.php`, `PackageSyncFailed.php`, `UnserveablePackageNames.php`, `Billing/BillingAlert.php` | All five end in `->title($this->title())->body($this->body())->actions([...markAsRead()])->getDatabaseMessage()`, differing only in warning/success tone and heroicon name. `title()`/`body()` are already the abstract pair `AnnouncedByMail` declares — add a sibling trait taking abstract `icon()`/`tone()` (and an optional context block for Slack). | 85 |
| 5.2 | Triplicated "serve the zip" tail: exists-guard, `PackageDownloaded::dispatch` on GET, `temporaryUrl` redirect with Cache-Control, `download` fallback | `app/Http/Controllers/VersionArchiveController.php:66`, `Pages/PackageArchiveController.php:70`, `ComposerRepositoryController.php:980` | Three copies differ only in the Cache-Control string (`no-store` vs `public, max-age=3600`) and the 404 message. `ArchiveStore` already owns `disk()`, `temporaryUrl()` and `downloadFilename()` — give it `serve(string $path, string $filename, string $cacheControl)`. | 45 |
| 5.3 | `RebuildPackage` service — both methods are one-line delegations | `app/Services/RebuildPackage.php` | `rebuild()` → `PackageSynchronizer::sync(force: true)`; `queue()` → `SyncPackageJob::dispatchUnlessPending(force: true)`. Inline at the 3 call sites (`RebuildPackageAction:33`, `QueueSyncsBulkAction:67`, `RebuildPackages:52`). | 43 |
| 5.4 | `RegistryMetrics` generic `metric($name,$type,$help,$samples[])` + `labels()` escaping machinery | `app/Services/RegistryMetrics.php:284` | 16 of 17 call sites pass `'labels' => []`; every family carries exactly one sample. Replace with direct `gauge()`/`counter()` emitters; the single labelled series (`up`) formats its one label inline. | 35 |
| 5.5 | Identical `package()` resolver in the two export **commands** | `app/Console/Commands/ExportDownloads.php:107`, `ExportSbom.php:98` | Bodies identical apart from a hoisted local; same query, same two `throw_if` messages verbatim. Share via `App\Console\Concerns`. | 30 |
| 5.6 | `CatalogSync` — fold into its callers | `app/Services/Billing/CatalogSync.php` | `syncAll()` is a 6-line foreach with exactly one caller (`SyncBillingCatalog:29`); the docblock's "panel action" caller does not exist. `syncPlan()` keeps 2 call sites — inline or move onto `Plan`. | 30 |
| 5.7 | `MailDelivery` class → 2-line helper or inline | `app/Support/MailDelivery.php` | 8 lines of code, 33 of comment, 2 consuming files (`UserForm`, `SendWelcomeEmailAction`). Keep the *why* comment at whichever site survives — the log/array-drivers-lie insight is worth its lines. | 25 |
| 5.8 | Byte-identical `package()` resolver in the two export **controllers** | `app/Http/Controllers/DownloadExportController.php:83`, `SbomExportController.php:76` | Same signature, same body, same abort message shape; both also repeat the identical `streamDownload` + `fopen('php://output')` closure. | 20 |
| 5.9 | Duplicated console helpers: `settled()` (2 prune commands), `emailError()` (2 user commands) | `CleanArchives.php:124` / `PruneMirror.php:138`; `AddUser.php:122` / `CreateAdmin.php:145` | Byte-for-byte identical. Both user commands already `use IssuesPasswordResetLinks` — move `emailError()` there. | 15 |
| 5.10 | `latestStableVersion()` + `isVersionLikeTag()` hand-rolled version ordering | `app/Services/PackageSynchronizer.php:646` | Second implementation of "highest stable version" — `VersionCeiling::currentCeiling` already sorts on the indexed `order` column in SQL. Reuse `VersionNormalizer::order()` or the column. | 15 |

**Batch total: ~343 lines**

## Batch 6 — speculative flexibility in billing values

Write-only fields and parameters that only ever receive one value.

| # | Cut | Path | Evidence | ~Lines |
|---|-----|------|----------|-------:|
| 6.1 | `CheckoutSession` value object → return the redirect URL string | `app/Merchants/Values/CheckoutSession.php` | Constructed once (`StripeClient:203`, 2 of 3 args), consumed once (`CheckoutController:37` reads only `->redirectUrl`). `$externalId` and `$clientSecret` have zero readers; "embedded flow later adds a client secret" is the speculation. | 19 |
| 6.2 | `CheckoutBuilder` `?User $contact` param + `instanceof Team` branches | `app/Services/Billing/CheckoutBuilder.php:74` | Sole caller is `CheckoutController:35` passing `$request->user()`; Team billing customers are created in Filament, never through checkout. `start(User $user, PlanPrice $price)`. | 12 |
| 6.3 | `CheckoutRequest` constant fields: `quantity` (always 1), `couponCode` (always null — Stripe owns coupons via `allow_promotion_codes`), `collectBusinessDetails` (always true) | `app/Merchants/Values/CheckoutRequest.php` | `new CheckoutRequest(` appears exactly once in the repo; all three are literals there. Hardcode in `StripeClient`. | 6 |
| 6.4 | Write-only VO fields: `RemoteSubscription::$priceExternalId`, `RemoteInvoice::$refundedAt` | `app/Merchants/Values/` | `priceExternalId` written at `StripeClient:371`, read by nobody (`SubscriptionProjector::apply` forceFills 10 fields, not this one). `refundedAt` is hardcoded `null` by its only producer; the real value is written by `invoiceRefunded` independently. | 4 |
| 6.5 | Fake DI: constructor-default collaborators nobody ever injects | `EntitlementProjector.php:175`, `SubscriptionProjector.php:526`, `VersionCeiling.php:697` | No call site passes an argument, no container binding exists. Plain `new` where used. | 9 |
| 6.6 | `SubscriptionTokens::withinLimit()` public → private | `app/Services/Billing/SubscriptionTokens.php:648` | Only caller is `issueFor()` in the same class. | 1 |
| 6.7 | `Plan::activePrices()` → inline into `purchasable()` | `app/Models/Plan.php:128` | One caller (`purchasable()`), which itself has one caller (`CheckoutController:26`). Zero blade/Filament use. | 5 |
| 6.8 | `VersionCeiling` pre-`order`-column backfill fallback (second query + map + max) | `app/Services/Billing/VersionCeiling.php:59` | The `order` column ships in the *initial* `create_package_versions_table` migration and is written on every insert — no deployed row can be null. `return $ceiling ?? self::NOTHING;`. | 14 |

**Batch total: ~70 lines**

## Batch 7 — config and dependency trim

| # | Cut | Path | Evidence | ~Lines |
|---|-----|------|----------|-------:|
| 7.1 | Postmark mailer block + services key | `config/mail.php:59`, `config/services.php:42` | `symfony/postmark-mailer` is not installed — selecting `MAIL_MAILER=postmark` fatals at boot. Dead and a trap. | 10 |
| 7.2 | `resend/resend-php` hard require → drop (or `suggest`) | `composer.json`, `config/mail.php:64`, `config/services.php:46` | Zero app code touches it; only stock config scaffolding, zero `services.resend` reads. SMTP covers self-hosted. **−1 dependency.** | 5 |
| 7.3 | `public` disk + `links` array | `config/filesystems.php:55,90` | `Storage::disk(config('filesystems.dists'))` in `ArchiveStore` is the only `Storage::` call in app/; `dists` resolves to `local\|s3` only. No `storage:link` anywhere. | 20 |
| 7.4 | `STRIPE_PUBLISHABLE` env + services key | `config/services.php:65`, `.env.example:229` | Hosted-checkout only — no Stripe.js, no `loadStripe`, zero readers. | 2 |
| 7.5 | Doc updates for the cuts above | `docs/merchant-drivers.md`, `docs/plans/ecommerce-subscriptions.md` | Both describe the merchant `StubClient` pattern and the full 12-method contract; rewrite those passages once batches 1–2 land. | — |

**Batch total: ~37 lines, −1 dep**

---

## Explicitly kept (audited, not bloat)

So nobody re-litigates these next audit:

- **`EgressPolicy` / `BoundedSink` / `BoundedSinkWrapper`** — SSRF/zip-bomb guards; every method reachable, wrapper shape is dictated by PHP's stream-wrapper API.
- **`PageMarkdown`** — not replaceable by `Str::markdown()`: escapes raw HTML from untrusted READMEs served on the admin origin, refuses `javascript:`/`data:` URLs, resolves relative links against the source repo.
- **`VersionNormalizer` / `HttpTimeouts`** — real logic, every constant used (the `LOGIN == CONNECT` coincidence is semantic, not duplication).
- **`HostResolver` interface** — one prod implementation but a real test seam (`FakeHostResolver` backs the whole egress test suite).
- **`MerchantClient` interface itself** — two live implementations behind `MerchantProvider::client()`; keep, but it shrinks to ~5 methods after batch 2.
- **`SubscriptionProjector`** — one public method but two genuinely different callers (webhook + reconciler); the `last_event_at` out-of-order guard is its reason to exist.
- **`concurrently` npm dep** — *looks* unused, but the framework's `artisan dev` shells `npx concurrently` (vendor `DevCommand:199`). An earlier pass flagged it; overturned.
- **`CancellationTiming::EndOfPeriod`** — no code reference, but it's a stored value selectable in Filament: data, not dead code.
- All API Resources (explicit field lists keep encrypted casts off the wire), all middleware (custom token tables, dual-mount resolution), all 18 blade views, every `registry.php` config key, all Filament widgets/actions/relation managers, migrations (can't squash — v1.0.0–v1.3.9 are tagged), `composer/semver` + `composer/metadata-minifier` (spec behaviour, worse hand-rolled), `PruneCache` (Laravel ships no database-store sweeper).
- **Empty `Controller` base class** — technically deletable, but touching 23 files to save 7 lines is churn, not laziness. Skip.

## Tally

| Batch | Theme | ~Lines |
|-------|-------|-------:|
| 1 | Pure deletes | 256 |
| 2 | Dead merchant contract | 142 |
| 3 | Gitea/Bitbucket stubs | 95 |
| 4 | Shield policy surface | 309 |
| 5 | Duplication | 343 |
| 6 | Speculative billing values | 70 |
| 7 | Config + deps | 37 |

**net: ~-1,250 lines, -1 dep.**
