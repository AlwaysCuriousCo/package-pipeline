# Packistry Feature Analysis & Implementation Prompts

Source analyzed: [packistry/packistry](https://github.com/packistry/packistry) (GPL-3.0, Laravel 12 + React SPA,
Sanctum, queued job batches, spatie/laravel-data, composer/semver).

Purpose: a pick-list of Packistry's features, each with a **condensed agentic prompt** you can run
independently in package-pipeline (Laravel 13 + Filament v5). Prompts assume the current codebase:

- Composer v2 endpoints exist: `packages.json`, `/p2/{vendor}/{package}.json` (incl. `~dev`), `/dist/...zip`
  in `app/Http/Controllers/ComposerRepositoryController.php` + `routes/web.php`
- `Package` / `PackageVersion` models, `app/Services/PackageSynchronizer.php`, `app/Services/GitHub/GitHubClient.php`
- Admin is Filament (`app/Filament/Resources`), no public SPA
- Migrations `2026_08_03_193140_create_sources_table.php`, `..._193145_create_packages_table.php`,
  `..._193150_create_package_versions_table.php` define the whole schema (pre-v1: folded in, never
  altered — deployed databases are rebuilt with `migrate:fresh` when the schema moves)

Legend: ✅ already have · 🟡 partial · ❌ missing

---

## Feature map (pick & choose)

| # | Feature | Status | Depends on |
|---|---------|--------|------------|
| 1 | Composer v2 API completeness (search.json, list.json, stable/dev split) | ✅ | — |
| 2 | Local archive storage + dist serving (zips, sha1, cleanup) | ✅ | — |
| 3 | Version normalization & ordering (composer/semver) | ❌ | — |
| 4 | Artifact upload endpoint (CI pushes a zip) | ✅ | 2 |
| 5 | Multiple named repositories (`/r/{path}`, public/private) | ✅ | — |
| 6 | Token authentication for Composer clients (http-basic) | ✅ | — |
| 7 | Deploy tokens (machine access, repo/package scoped) | ✅ | 6 |
| 8 | Personal access tokens (per-user) | ✅ | 6 |
| 9 | Roles + granular permissions | ✅ | — |
| 10 | Per-user repository/package access scoping | ✅ | 5, 9 |
| 11 | Provider webhooks (auto-sync on push/tag/delete) | ✅ | — |
| 12 | Queued batch imports with progress | ✅ | — |
| 13 | Multi-provider source abstraction (GitLab, Gitea, Bitbucket) | 🟡 | — |
| 13a | Sources with GitHub App auth + package bridging | ✅ | — |
| 14 | Source project browser + one-click package onboarding | ❌ | 13 |
| 15 | Download statistics + dashboard | ✅ | 2 |
| 16 | SSO / authentication sources (OIDC, GitHub, Google…) | ❌ | 9 |
| 17 | Operational CLI commands | ✅ | varies |
| 18 | Package rebuild & maintenance jobs | 🟡 | 2, 12 |

Suggested independent tracks: **Core registry** (1–4), **Access control** (5–10), **Sync pipeline**
(11–14), **Observability/ops** (15, 17, 18), **Auth UX** (16).

---

## 1. Composer v2 API completeness

**What Packistry does** (`app/Http/Controllers/Composer/RepositoryController.php`):
`packages.json` advertises `metadata-url`, `search` (`/search.json?q=%query%&type=%type%`), and `list`
(`/list.json`). `search.json` returns `{total, results:[{name, description, downloads}]}` filtered by
name prefix and package type. `list.json` returns `{packageNames:[...]}`. `/p2/{vendor}/{name}.json`
serves only stable versions; `/p2/{vendor}/{name}~dev.json` serves only `dev-*` / `*-dev` versions.
Packistry does **not** use `available-packages` (which package-pipeline currently emits — it defeats
lazy loading and leaks the package list).

**Implemented**: `root()` now advertises `search` and `list` instead of `available-packages`;
`/search.json` filters served packages by name prefix (LIKE wildcards in the query are escaped) and
optional `type`, returning `{total, results:[{name, description, downloads}]}` with `downloads: 0`
until download tracking (item 15) exists; `/list.json` returns `{packageNames:[...]}` sorted. Both
endpoints only advertise packages that have at least one synced version — an unsynced package
resolves to nothing, so listing it would be a dead end. Covered in
`tests/Feature/ComposerRepositoryTest.php`.

## 2. Local archive storage + dist serving

**What Packistry does** (`app/CreateFromZip.php`, `Version::archive_path`, `download()` action):
Every version stores its zip on a Laravel `Storage` disk under `{repository->path}/{uuid7}.zip`,
records `shasum` (sha1 of the file), and serves downloads via `Storage::download()` with a 404 when the
archive is missing. `archives:clean` command prunes orphaned files. Dist URLs in metadata point at the
app, never at GitHub, so consumers never need GitHub credentials.

**Implemented**: `package_versions` carries nullable `archive_path` + `shasum` (folded into the
create migration, pre-v1). `PackageSynchronizer` downloads the zipball for every new or moved
reference during sync — `GitHubClient::downloadZipball()` rejects a 200 whose content-type is not a
zip — and `app/Services/ArchiveStore.php` stores it at `packages/{vendor}/{name}/{uuid7}.zip`,
recording the path and the file's sha1. Unchanged refs skip the download entirely, and a row missing
its archive (synced before this feature, or a file gone missing) is treated as changed so the next
sync backfills it. `ComposerRepositoryController::dist()` streams the stored archive and 404s when
none is stored — it never reaches for GitHub — and `metadata()` advertises `dist.shasum` so Composer
verifies downloads. `archives:clean {--dry-run}` prunes files no version references (replaced
archives get a fresh uuid path rather than an overwrite, so orphans are expected between cleans).
One deliberate departure from the prompt: archives live on the existing configurable dist disk
(`filesystems.dists`, `DIST_DISK`) rather than the default disk, since that knob already existed for
the request-time cache this replaces. Covered in `tests/Feature/ComposerRepositoryTest.php`,
`tests/Feature/PackageSyncTest.php`, and `tests/Feature/CleanArchivesCommandTest.php`.

## 3. Version normalization & ordering

**What Packistry does** (`app/Normalizer.php`): normalizes tags (`v1.2.3` → `1.2.3`,
pre-release suffixes like `-beta.2` → `1.2.3-beta2`), maps branches to dev versions
(`main` → `dev-main`, `2.x` → `2.x-dev`, `2` → `2.x-dev`), and computes a sortable `order` string via
`composer/semver`'s `VersionParser::normalize()` stored on every version. "Latest version" decisions
(e.g. which version's composer.json updates the package name/description/type) compare `order`, and
`/p2` responses sort by it. A `NormalizeVersionOrder` job re-normalizes after algorithm changes.

**Gap**: package-pipeline sorts with `orderByDesc('version')` (string sort — `1.10.0` < `1.9.0`) and
has ad-hoc tag/branch mapping in `PackageSynchronizer`.

```text
In this Laravel app, add proper Composer version normalization:
1. composer require composer/semver.
2. Create app/Support/VersionNormalizer.php with: version(string): string (strip leading v, normalize
   pre-release suffixes; pass through dev-* and *-dev), devVersion(string branch): string
   (main -> dev-main, 2.x -> 2.x-dev, numeric 2 -> 2.x-dev), and order(string): string using
   Composer\Semver\VersionParser::normalize (dev-* returns as-is).
3. Migration: add `order` (string, indexed) to package_versions; backfill existing rows in the
   migration using the normalizer.
4. Use it in app/Services/PackageSynchronizer.php when creating versions, and replace
   orderByDesc('version') in ComposerRepositoryController with orderByDesc('order') for stable
   versions (dev versions keep name ordering).
5. Unit-test the normalizer against edge cases: v1.2.3, 1.2, 1.2.3.4, 1.0.0-beta.2, RC tags,
   branches main/develop/2.x/2, and verify 1.10.0 sorts above 1.9.0. Run tests.
```

## 4. Artifact upload endpoint (push from CI)

**What Packistry does** (`upload()` in Composer/RepositoryController + `app/CreateFromZip.php`):
`POST /{vendor}/{name}` with multipart `file` (zip) and optional `version` creates the package on the
fly and a version from the zip's `composer.json` (name/description/type extracted; a curated subset of
composer.json keys — require, autoload, scripts, license, authors, etc. — stored as version metadata).
Requires a write-ability token. This enables "build artifact → publish" pipelines with no VCS source.

**Implemented**: `POST /upload/{vendor}/{package}` (and `/r/{path}/upload/...` per repository) —
one deliberate departure from Packistry's bare `POST /{vendor}/{package}`, which would collide with
the `/incoming` webhook paths and force a wildcard CSRF exemption; the `/upload` segment gets its
own narrow exemption and always renders errors as JSON, since CI curls rarely send an Accept
header. Requires `repository:write`, and the principal's *scope* must reach the addressed
repository — a scoped deploy token publishes only into granted repositories (or onto its granted
packages); public means readable, never writable. `CreateVersionFromZip` finds composer.json at the
top level or one folder deep, 422s a manifest naming a different package than the URL, resolves the
version from input else manifest (422 when neither), stores the curated metadata subset, and stores
the zip through `ArchiveStore` in one transaction; the version's dist reference is the file's own
sha1, so a re-published version is a new URL rather than a silently different file. Uploaded
packages carry a null VCS `repository` (column made nullable, pre-v1), webhooks off, and no sync
action; `Package::refreshLatestVersion()` keeps `latest_version` honest. Covered in
`tests/Feature/ArtifactUploadTest.php`.

## 5. Multiple named repositories

**What Packistry does** (`app/Models/Repository.php`, route group `/r/{repository}`): repositories have
`name`, `path` (URL slug), `description`, `public` flag. All Composer routes are mounted twice: at the
root (default repository, `path = null`) and under `/r/{path}`. Packages belong to exactly one
repository; archives are stored under the repository's path. Public repositories skip auth for reads;
private ones require a token.

**Implemented**: a `repositories` table (name unique, path unique nullable slug, description,
`public` boolean) with the FK folded into the packages create migration (pre-v1); package names and
VCS URLs are unique per `(repository_id, …)`. The Composer routes are one group mounted twice — at
the root and under `/r/{path}` — with `ResolveComposerRepository` middleware resolving which
repository a request addresses (404 on unknown paths) and every controller query scoped to it;
metadata/search/list/dist URLs are generated inside the resolved mount, and a package's install
commands point at it (with the path suffixed onto the composer.json config key so two repositories
never fight over one entry). Departures from Packistry: the model relation is `composerRepository()`
because `Package::$repository` already means the VCS URL; and the default repository (path null,
served at the root) is system-owned — created lazily by `Repository::default()`, seeded for fresh
installs, its path not editable and the row not deletable — and is created **public** so existing
deployments keep serving openly, while admin-created repositories start **private**. `public` is
enforced by token auth (item 6). Covered in `tests/Feature/ComposerRepositoryScopingTest.php` and
`tests/Feature/RepositoryResourceTest.php`.

## 6. Token authentication for Composer clients

**What Packistry does** (`app/Models/Token.php`, Sanctum-style, `Tokenable` contract,
`tokenScoped()` builders): Composer endpoints authenticate via HTTP Basic / bearer token. Tokens are
hashed at rest (only prefix + hash stored), have abilities `repository:read` / `repository:write`,
soft-delete for revocation, and `last_used_at`. Every Composer query runs through `tokenScoped()`,
which limits visible packages to public repositories plus whatever the token grants. Consumers
configure `composer config http-basic.<host> token <plain-token>`.

**Implemented** as specified: `access_tokens` (morphs to its owning principal, name, prefix, sha256
hash unique, abilities json, last_used_at, expires_at, soft-deletes) with `Token::issue()` returning
a `NewToken` value object carrying the only copy of the plain text (`pp_` + 40 chars);
`AuthenticateComposer` middleware accepts the token as the HTTP Basic password (any username) or a
bearer token, 401s with the exact `composer config http-basic.<host> token <token>` fix (plus a
`WWW-Authenticate: Basic` challenge so interactive installs prompt), 403s a token without the
required ability (`TokenAbility` enum: `repository:read` / `repository:write`), and touches
`last_used_at` at most once a minute without bumping `updated_at`. Reads on a public repository
pass without a token, but a *presented* credential is always verified — a CI box with a revoked
token hears about it as a 401 immediately. One departure: there is no admin-wide TokenResource —
personal tokens are self-service on the user-menu page (item 8), and machine tokens are item 7's
DeployTokenResource, matching Packistry's own split. Covered in `tests/Feature/ComposerAuthTest.php`.

## 7. Deploy tokens (machine access)

**What Packistry does** (`app/Models/DeployToken.php` + pivot tables `deploy_token_repository`,
`deploy_token_package`): deploy tokens are non-user principals for CI/CD. Each is scoped to a set of
repositories and/or individual packages (or unscoped = everything); `tokenScoped()` unions those
grants. They authenticate exactly like user tokens and appear in download stats.

**Implemented** as specified: `deploy_tokens` (name) with `deploy_token_package` /
`deploy_token_repository` pivots, its access token issued through the shared polymorphic `Token`
mechanics (deleting the deploy token soft-deletes — revokes — its tokens; the pivots cascade).
`Package::visibleTo(?Token)` is the single access-control scope every Composer endpoint
(search/list/metadata/dist) runs through: no token → public repositories only; user token → the
owner's reach; scoped deploy token → public repositories ∪ granted packages ∪ packages in granted
repositories; unscoped deploy token → everything. `DeployTokenResource` sits in the Access
Management group — create shows the plain text once (persistent notification with the ready-made
`composer config` line) and issues **read** ability only; write is granted deliberately through the
edit page's "Regenerate token" action, which also serves as rotation (old token dies the moment the
new one exists). Covered in `tests/Feature/DeployTokenTest.php`.

## 8. Personal access tokens (per-user)

**What Packistry does** (`PersonalTokenController`, `HasApiTokens` on `User`): users self-issue tokens
(name + abilities + optional expiry) from their profile; the token inherits the *user's* repository and
package access. Listing shows prefix + last used; delete revokes.

**Implemented**: `access_tokens.tokenable` is polymorphic from the start, so User and DeployToken
principals share one issuing/hashing/lookup mechanism. The "API tokens" page hangs off the user
menu (`app/Filament/Pages/ApiTokens.php`) — create with name, read/write abilities and optional
expiry (valid through the whole chosen day), the plain text rendered once on the page alongside the
ready-to-paste `composer config http-basic...` line, a listing showing prefix / abilities /
last-used / expiry, and revoke (soft delete). The page pins every query to the signed-in user, so
it needs no Shield permission and every panel user gets it. Per-user package visibility is item
10's concern. Covered in `tests/Feature/ApiTokensPageTest.php`.

## 9. Roles + granular permissions

**What Packistry does** (`app/Enums/Role.php`, `app/Enums/Permission.php`, `config/authorization.php`):
two roles (admin, user) mapped in config to a flat permission enum covering CRUD per domain
(repository_*, package_*, user_*, source_*, deploy_token_*, personal_token_*, authentication_source_*,
batch_*) plus `dashboard` and `unscoped` (bypasses row-level scoping). A `Gate::before`-style check
resolves `$user->can(Permission::X)` from the role → permission map. Simple, no DB permission tables.

**Implemented**, as one deliberate departure: roles and permissions are database-backed via
`bezhansalleh/filament-shield` (spatie/laravel-permission underneath) rather than Packistry's
config-enum map. Shield derives one permission per panel entity action (`ViewAny:Package`,
`Create:Source`, …) from the panel itself (`ShieldPermissionSeeder` regenerates them every seed, so
they cannot drift as resources are added), generated policies in `app/Policies` enforce them on every
resource, and `User::canAccessPanel()` requires holding at least one role — a stray user row is never
a way in. Roles are managed in the panel's own Roles resource, so an admin can shape any number of
roles at runtime instead of the two the config file would freeze; `admin:create` grants the
super-admin role with every permission. Row-level visibility (Packistry's `unscoped`) is item 10's
concern, not a permission flag here.

## 10. Per-user repository/package access scoping

**What Packistry does** (`repository_user`, `package_user` pivots, `userScoped()` builders): non-admin
users are granted specific private repositories and/or individual packages. Every listing (API,
dashboard counts, download stats) funnels through `userScoped()`: public repos ∪ granted repos ∪
granted packages, with `unscoped` permission bypassing. Authentication sources can also auto-grant
package access (`authentication_source_package`).

**Implemented**: `package_user` / `repository_user` pivots; `Package::visibleToUser(User)` returns
everything for holders of the **`Unscoped:Package`** permission (Packistry's `unscoped`, declared in
`ShieldPermissionSeeder` since Shield only derives CRUD permissions, and grantable to any role on
the Roles screen's custom-permissions tab — the super admin holds it like everything else) and
public repositories ∪ granted packages ∪ granted repositories for everyone else. Applied on
`PackageResource::getEloquentQuery()` — so the table *and* record pages 404 out-of-reach packages —
on the release heatmap widget, and, through `visibleTo()`, on the user's personal access tokens:
a token reaches exactly what its owner can see. Grants are edited as two multi-selects on the User
form; the inverse users-select on the Package form was skipped deliberately — grants are a
user-management concern and one place to edit them keeps the story straight. Covered in
`tests/Feature/UserAccessScopingTest.php`.

## 11. Provider webhooks (auto-sync on push)

**What Packistry does** (`app/Http/Controllers/Webhook/*`, `app/Sources/*/Event/*`): per-provider
endpoints `POST /incoming/{github|gitlab|gitea|bitbucket}/{sourceId}` validate signatures
(GitHub/Gitea: HMAC `X-Hub-Signature-256`; GitLab: secret token header) against the source's stored
secret. Push events resolve the package by `(source_id, provider_id)` and import just that tag/branch
(zipball download → CreateFromZip); delete events remove the corresponding version (and its archive).
`ping` returns 204; unknown events 422. Webhooks are auto-registered on the provider when a package is
onboarded (`Client::createWebhook`).

**Implemented**, with three deliberate departures from Packistry's design — see
[webhooks.md](webhooks.md):

- **An app-level webhook in front of Packistry's per-package one.** Packistry only has token auth,
  so a hook on each repository is its only option. Sources here can authenticate as a GitHub App,
  which has a single webhook covering every repository in every installation
  (`POST /incoming/github`, signed with `GITHUB_APP_WEBHOOK_SECRET`) — nothing per package, new
  repositories covered for free, and no permission beyond the read-only ones syncing already needs.
  Packistry's approach remains as the fallback and the general case: `WebhookRegistrar` creates a
  hook on the repository at package-create time (`POST /incoming/github/{package}`, its own
  encrypted secret) whenever the app webhook is not covering it — including on a registry that has
  simply not set the app webhook up. Configuring it is offered on the source page, where the payload
  URL and a generated secret are shown, rather than left to this document.
- **A full sync per delivery, not a single-ref import.** `PackageSynchronizer::unchanged()` already
  skips refs whose sha has not moved, so a delivery costs two API calls when nothing changed;
  a separate surgical import path would be a second implementation of version building to keep
  correct. The sync is queued 15 seconds out so a `git push --tags` burst collapses into one run
  (the existing `ShouldBeUniqueUntilProcessing` lock does the folding).
- **Unhandled events are 202, not 422.** An app webhook receives whatever the app is subscribed to,
  including repositories that are not packages here — the normal case, not an error, and a 4xx
  would paint GitHub's delivery log red for working installations.

Beyond the original scope: `sync()` now returns a `SyncOutcome`, which is what lets a new tag raise
a notification (panel bell + Slack) while a dev branch moving stays silent, and a permanently
failed sync announce itself.

## 12. Queued batch imports with progress

**What Packistry does** (`app/Jobs/*`, `PackageImportBatch`, `BatchController`): onboarding a package
dispatches a `Bus::batch` of `ImportBranches` + `ImportTags`; each lazily pages through provider refs
and fans out one `ImportImportable` job per ref (batch `allowFailures()`). The UI polls `/batches` to
show progress (total/processed/failed) and can prune finished batches. Result: importing a package with
hundreds of tags doesn't block a request and failures are per-version, not all-or-nothing.

**Implemented**, with the batch layered under the existing entry point rather than replacing it.
`PackageSynchronizer` is decomposed into steps — `discover()`, `resolveComposerName()`, `prune()`,
`changed()`, `import()` (one ref: composer.json, commit date, row + archive in one transaction),
`finalize()` — so there is exactly one implementation of version building with two drivers:
`sync()` composes the steps inline for `packages:sync` and the tests, and the batch
(`App\Jobs\PackageSyncBatch::dispatchFor`) runs them as a named `Bus::batch` with
`allowFailures()`: `DiscoverVersions` lists refs, prunes, and fans out one `ImportVersion` job per
ref whose sha moved (unchanged refs add no job at all, so a routine sync is a batch of one), and a
`FinalizePackageSync` `finally` callback recomputes the package's columns, sets
`last_synced_at`/`sync_error`, and sends the item-11 notifications. The batch id is stored on the
package (`sync_batch_id`, folded into the create migration pre-v1) and a polling widget on the
package view page renders imported/total/failed with a progress bar while
`Package::syncRunning()` — which knows that a batch with failures never gets `finished_at` — says
the imports are still going.

Deliberate departures from the prompt:

- **`SyncPackageJob` stays the single entry point** and now only starts the batch: the webhook
  debounce, uniqueness lock, and "sync already queued" panel affordance all hang off that job, and
  a sync arriving while a batch is still importing re-queues itself for after it finishes (with a
  2-hour staleness override) instead of racing it — two rebuilds pruning concurrently was the bug
  the old `WithoutOverlapping` prevented, and the batch inherits that guarantee here.
- **Pruning happens at discovery, not in the finally callback.** The discovered ref list is the
  authority on what exists upstream; waiting for the imports would keep serving versions already
  deleted. The finally callback instead recomputes package metadata from what the imports stored.
- **Partial failure is a recorded state, not an error**: the batch finishes, `last_synced_at` is
  set, and `sync_error` reads "N of M version imports failed; the next sync will retry them" —
  which the next sync does, because an incomplete row is treated as changed.
- Inline `sync()` keeps the same per-ref isolation (a lone bad ref costs its version, not the
  sync), throwing only when nothing at all could be imported.

The `job_batches` table already existed in the default jobs migration. Covered in
`tests/Feature/PackageSyncBatchTest.php` (fan-out, no-op re-sync, failure isolation on a real
database queue, discovery-failure cancellation, deferral while a batch runs, widget states) plus
the reworked `tests/Feature/PackageSyncTest.php` / `SyncPackageJobTest.php`.

## 13a. Sources with GitHub App auth — implemented

The `Source` model, its Filament resource, and the GitHub App install handshake are built; see
[github-app.md](github-app.md). A source holds one GitHub owner and mints short-lived installation
tokens, packages under that owner are linked to it on save, and `GitHubClient::for()` resolves
source → package token → `GITHUB_TOKEN`. The provider is an enum (`App\Enums\SourceProvider`) but
only GitHub is implemented — item 13 below is the remaining work: extracting a `SourceClient`
contract and adding GitLab/Gitea/Bitbucket behind it.

## 13. Multi-provider source abstraction

**What Packistry does** (`app/Sources/Client.php` + GitHub/Gitlab/Gitea/Bitbucket implementations,
`app/Models/Source.php`, `SourceProvider` enum): a `Source` row = provider type + base URL + encrypted
token (+ metadata). The abstract client exposes `projects(search)`, `project(id)`, `branches()`,
`tags()` (LazyCollections over paginated APIs), `createWebhook()`, `validateToken()`, and archive
download; the rest of the app is provider-agnostic (webhook event classes per provider adapt payloads
to shared `Importable`/`Deletable` interfaces). Supports self-hosted instances via custom base URLs.

```text
In this Laravel app (GitHub-only sync via app/Services/GitHub/GitHubClient.php), introduce a
provider-agnostic source layer:
1. Model + migration: sources table (name, provider enum string [github, gitlab, gitea, bitbucket],
   base_url, token encrypted cast, metadata json nullable). Add nullable source_id +
   provider_id (external project id) on packages; keep the per-package token column working as a
   legacy fallback during transition.
2. Contract app/Sources/SourceClient.php: projects(?string search): array{id, fullName, webUrl},
   tags(project) and branches(project) as lazy iterables of {name, sha, zipUrl}, composerJson(ref),
   downloadZip(url), createWebhook(package), validateToken(). Port GitHubClient to implement it;
   add GitLabClient (API v4: /projects, /repository/tags, /repository/branches, archive.zip
   endpoints, PRIVATE-TOKEN header). Stub Gitea/Bitbucket classes behind the same contract (throw
   UnsupportedProviderException until implemented) so adding them later is mechanical.
3. Source::client() factory resolves the right implementation; PackageSynchronizer (and jobs, if
   batch sync exists) depend only on the contract.
4. Filament: SourceResource (provider select, base_url, token, "validate token" action calling
   validateToken()).
5. Tests with Http::fake per provider fixture covering tags/branches pagination and composer.json
   fetch. Run tests.
```

## 14. Source project browser + one-click onboarding

**What Packistry does** (`SourceController::projects`, `StorePackage` action): the UI lists/searches
projects available to a source token (`GET /sources/{id}/projects?search=`), lets you pick several,
optionally auto-creates the provider webhook per project, creates each package linked by
`(source_id, provider_id)`, and kicks off the import batch — packages onboard in one step.

```text
In this Laravel + Filament app (sources table + SourceClient contract + batch sync exist), build
package onboarding from a source: a Filament page or PackageResource "Import from source" action
where the user picks a Source, searches its projects live (SourceClient::projects), multi-selects
projects, and toggles "create webhook". On submit: for each project, firstOrCreate the Package
(source_id + provider_id + name from project fullName), optionally call createWebhook (surface
per-project failures as validation errors without aborting the rest), and dispatch the sync batch.
Show created packages with links. Tests: onboarding two fake projects creates packages and
dispatches batches (Bus::fake, Http::fake). Run tests.
```

## 15. Download statistics + dashboard

**What Packistry does** (`Download` model, `PackageDownloadEvent` + listener, `DashboardController`,
`total_downloads` counters, `RecalculateTotalDownloads` command): every dist download fires an event
recording package, version, ip, and the token used; a `downloads` table (indexed on created_at) powers
a 90-day per-day chart; denormalized `total_downloads` on packages/versions feeds search results and
lists. The dashboard shows permission-gated counts (packages, repos, users, tokens, sources) plus the
chart.

**Implemented** as specified: a `downloads` table (package FK, nullable version FK *plus* the
version string so history survives pruned branches, ip, the fetching token's prefix, indexed
created_at) and `total_downloads` counters folded into the packages/package_versions create
migrations (pre-v1). `dist()` dispatches `PackageDownloaded` — scalars, not models, so the queued
`RecordDownload` listener still counts a download whose version row was pruned before the job ran —
only after every 404 check has passed; the listener writes the row and bumps both counters.
`downloads:recalculate` rebuilds the counters with two correlated-subquery updates (portable across
SQLite/MySQL/Postgres). Dashboard: `RegistryStatsOverview` (packages, versions, downloads over 30
days / all time) and a 90-day per-day `DownloadsChart`, both scoped through `visibleToUser()` like
the release heatmap — a granted user reads a dashboard about *their* packages; `search.json` serves
the real counter, and the package table gains a sortable Downloads column. Grouping happens in PHP
(same reasoning as the heatmap: date truncation has no portable SQL spelling). Covered in
`tests/Feature/DownloadStatsTest.php`.

## 16. SSO / authentication sources

**What Packistry does** (`AuthenticationSource` model, `app/OIDCProvider.php`, Socialite-style flow):
admins configure login providers at runtime (OIDC discovery URL, GitHub, GitLab, Bitbucket, Google)
with client id + encrypted secret, an active flag, `allow_registration`, `allowed_domains` (email
domain allowlist), and a `default_user_role` for just-in-time provisioned users. Login page lists
active sources; callback matches users by `external_id`/email. Optionally maps an auth source to
package grants (`authentication_source_package`).

```text
In this Laravel + Filament v5 app (Filament handles login; users have a role column), add runtime-
configurable SSO:
1. composer require laravel/socialite (+ socialiteproviders/manager for generic OIDC).
2. Model + migration: authentication_sources (name, provider enum [oidc, github, google, gitlab],
   client_id, client_secret encrypted cast, discovery_url nullable for oidc, active boolean,
   allow_registration boolean default true, allowed_domains json nullable, default_user_role
   string default 'user') and users.external_id nullable string with (provider, external_id) unique.
3. Routes /auth/{source}/redirect and /auth/{source}/callback driving Socialite with credentials
   loaded from the row (for oidc, resolve endpoints from the discovery document, cached). Callback:
   match user by external_id, else by email; if none and allow_registration and email domain passes
   allowed_domains, create the user with default_user_role; then Filament::auth()->login().
4. Extend the Filament login page to render one "Continue with {name}" button per active source.
5. Filament resource for authentication_sources (admins only).
6. Tests with Socialite mocked: existing-user login, JIT registration, domain rejection,
   registration disabled. Run tests.
```

## 17. Operational CLI commands

**What Packistry does** (`app/Console/Commands`): interactive `add:*` / `delete:*` commands for user,
repository, package, source, deploy-token; `reset:password`; `rebuild:package`;
`downloads:recalculate`; `archives:clean`. Everything the UI does is scriptable for provisioning and
recovery (their Docker quickstart creates the first user this way).

**Implemented**: `user:add` (roles validated against what exists, prompts interactively, prints the
same short-lived signed reset link `admin:create` uses — extracted into the shared
`IssuesPasswordResetLinks` concern, so a password still never travels through the environment) and
`user:reset-password`; `package:add` (URL → guessed composer name, `--repo` targets a named
Composer repository, webhook registration and the first queued sync exactly like the wizard,
`--no-webhook`/`--no-sync` to opt out) and `package:delete` (confirmation, `--repo` disambiguation
when a name is served twice, and the stored archives go with the rows); `token:add` (`--user` email
or `--deploy` name — created on demand as an unscoped principal — abilities `read`/`write`,
optional expiry, plain text printed once with the `composer config` line) and `token:revoke` by the
prefix listings show. Syncing was already scriptable as `packages:sync {name?} {--source=}
{--queue}`, alongside `archives:clean` and `downloads:recalculate`. Covered in
`tests/Feature/OperationalCommandsTest.php`.

## 18. Package rebuild & maintenance

**What Packistry does** (`RebuildPackage` action + command, `rebuild` API endpoint,
`NormalizeVersionOrder` job): "rebuild" wipes a package's imported state and re-runs the full import
batch from its source — the recovery hammer for webhook drift, normalizer changes, or corrupted
archives. Also `renormalize_version_order` migration/job pattern for retroactive fixes.

```text
In this Laravel + Filament app, add package rebuild: a RebuildPackage service that, for a given
Package, re-runs a full sync from its source (reusing PackageSynchronizer / the sync batch if
present), re-downloading archives for versions whose stored file is missing and pruning versions
that no longer exist upstream; expose it as a Filament table+page action ("Rebuild") with
confirmation and as artisan package:rebuild {name?} (all packages when omitted, with progress bar).
Ensure rebuild is idempotent (running twice yields identical rows/files). Feature test: mutate a
version row and delete an archive file, rebuild, assert both restored. Run tests.
```

---

## Notable design choices worth copying (regardless of feature picks)

- **Store a curated metadata subset, not the whole composer.json** (`CreateFromZip::create`) — keeps
  `/p2` payloads lean and avoids leaking internal fields.
- **`order` column via composer/semver** instead of sorting version strings — correctness for
  `1.10 > 1.9`, pre-releases, and dev branches.
- **Zips are the source of truth**: every version's metadata comes from the archive actually served,
  so metadata and dist can never disagree.
- **Encrypted casts for all provider secrets** (source tokens, OAuth client secrets, webhook secrets).
- **`tokenScoped()`/`userScoped()` builder methods** applied at the query level everywhere, instead of
  per-controller filtering — one place to audit access control.
- **`allowFailures()` batches with per-ref jobs** — one broken tag never blocks the other 200.
- Things Packistry does *not* do (scope guardrails): no Packagist mirroring/proxying, no package
  signing, no vulnerability scanning, no npm/other ecosystems — it is strictly a private Composer
  registry fed by VCS sources and artifact uploads.
