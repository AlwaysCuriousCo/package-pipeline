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
  `..._193150_create_package_versions_table.php` define the whole schema (pre-v1: folded in, never altered)

Legend: ✅ already have · 🟡 partial · ❌ missing

---

## Feature map (pick & choose)

| # | Feature | Status | Depends on |
|---|---------|--------|------------|
| 1 | Composer v2 API completeness (search.json, list.json, stable/dev split) | 🟡 | — |
| 2 | Local archive storage + dist serving (zips, sha1, cleanup) | 🟡 | — |
| 3 | Version normalization & ordering (composer/semver) | ❌ | — |
| 4 | Artifact upload endpoint (CI pushes a zip) | ❌ | 2 |
| 5 | Multiple named repositories (`/r/{path}`, public/private) | ❌ | — |
| 6 | Token authentication for Composer clients (http-basic) | ❌ | — |
| 7 | Deploy tokens (machine access, repo/package scoped) | ❌ | 6 |
| 8 | Personal access tokens (per-user) | ❌ | 6 |
| 9 | Roles + granular permissions | ❌ | — |
| 10 | Per-user repository/package access scoping | ❌ | 5, 9 |
| 11 | Provider webhooks (auto-sync on push/tag/delete) | ❌ | — |
| 12 | Queued batch imports with progress | 🟡 | — |
| 13 | Multi-provider source abstraction (GitLab, Gitea, Bitbucket) | 🟡 | — |
| 13a | Sources with GitHub App auth + package bridging | ✅ | — |
| 14 | Source project browser + one-click package onboarding | ❌ | 13 |
| 15 | Download statistics + dashboard | ❌ | 2 |
| 16 | SSO / authentication sources (OIDC, GitHub, Google…) | ❌ | 9 |
| 17 | Operational CLI commands | 🟡 | varies |
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

**Gap**: package-pipeline lacks `search.json`/`list.json`, and exposes all names via `available-packages`.

```text
In this Laravel app (a private Composer v2 repository), extend app/Http/Controllers/ComposerRepositoryController.php:
1. Replace the `available-packages` key in root() with `list` and `search` keys pointing to new
   routes /list.json and /search.json?q=%query%&type=%type% (absolute URLs via url()).
2. Add search(): filter Package by name prefix (LIKE "q%") and optional composer `type` column,
   return {total, results: [{name, description, downloads}]} (downloads 0 if not tracked yet).
3. Add list(): return {packageNames: [...]} sorted by name.
4. Keep the existing /p2 metadata behavior (stable vs ~dev split already implemented).
5. Register routes in routes/web.php next to the existing composer.* routes and add feature tests
   covering root/search/list JSON shapes, verifying Composer v2 spec compliance
   (https://repo.packagist.org/packages.json format). Run the full test suite.
```

## 2. Local archive storage + dist serving

**What Packistry does** (`app/CreateFromZip.php`, `Version::archive_path`, `download()` action):
Every version stores its zip on a Laravel `Storage` disk under `{repository->path}/{uuid7}.zip`,
records `shasum` (sha1 of the file), and serves downloads via `Storage::download()` with a 404 when the
archive is missing. `archives:clean` command prunes orphaned files. Dist URLs in metadata point at the
app, never at GitHub, so consumers never need GitHub credentials.

**Gap**: package-pipeline builds dist zips at request time by proxying GitHub (check
`ComposerRepositoryController::dist()`); no shasum, no persistent archives, no pruning.

```text
In this Laravel app, make package version archives first-class:
1. Migration: add nullable `archive_path` (string) and `shasum` (string, sha1) to package_versions.
2. Create app/Services/ArchiveStore.php: given a PackageVersion and a local zip path, store it on the
   default Storage disk at "packages/{vendor}/{name}/{uuid}.zip", record archive_path + sha1 shasum.
3. During sync (app/Services/PackageSynchronizer.php), download the GitHub zipball for each new
   reference (reuse app/Services/GitHub/GitHubClient.php; validate content-type is zip), pass it
   through ArchiveStore, and skip re-downloading versions whose reference is unchanged.
4. Update ComposerRepositoryController::dist() to stream the stored archive
   (Storage::download) and 404 when archive_path is null/missing; update metadata() to include
   dist.shasum. Keep dist URL shape unchanged.
5. Add artisan command archives:clean {--dry-run} that deletes stored files not referenced by any
   PackageVersion, printing each removal.
6. Feature tests with Storage::fake and Http::fake covering store, serve, 404, and clean. Run tests.
```

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

```text
In this Laravel app (private Composer repository, Filament admin), add an artifact upload endpoint:
1. Route: POST /{vendor}/{package} (name it composer.upload) accepting multipart `file` (required,
   zip) and optional `version` string. Guard it with the app's Composer token auth if present,
   otherwise a config('services.composer.upload_token') bearer check as a stopgap.
2. Create app/Services/CreateVersionFromZip.php: open the zip (ZipArchive), locate composer.json at
   any top-level folder depth, decode it; resolve version from input or composer.json version key
   (error 422 if neither); firstOrCreate the Package by "{vendor}/{package}"; upsert a
   PackageVersion with metadata limited to keys Composer needs (description, license, authors,
   keywords, homepage, support, bin, autoload, autoload-dev, require, require-dev, conflict,
   provide, replace, suggest, minimum-stability, prefer-stable, scripts, extra); store the zip via
   the archive storage service if present (app/Services/ArchiveStore.php), else under
   storage/app/packages, recording sha1.
3. Return 201 with the version JSON; 422 with validation-style errors for bad zip / missing name.
4. Feature tests: successful upload creates package+version+archive; re-upload same version
   overwrites; missing composer.json rejected. Run tests.
```

## 5. Multiple named repositories

**What Packistry does** (`app/Models/Repository.php`, route group `/r/{repository}`): repositories have
`name`, `path` (URL slug), `description`, `public` flag. All Composer routes are mounted twice: at the
root (default repository, `path = null`) and under `/r/{path}`. Packages belong to exactly one
repository; archives are stored under the repository's path. Public repositories skip auth for reads;
private ones require a token.

```text
In this Laravel app, introduce multiple Composer repositories:
1. Model + migration: repositories table (name unique, path unique nullable slug, description
   nullable, public boolean default false). Add repository_id FK on packages (nullable during
   migration; backfill by creating a default repository with path null and assigning all packages
   to it, then make it non-nullable). Package name uniqueness becomes unique per (repository_id, name).
2. Routing: extract the composer routes in routes/web.php into a reusable group and mount them both
   at root (resolving the default repository) and under prefix /r/{repositoryPath}. Resolve the
   Repository in ComposerRepositoryController via a shared method that 404s on unknown path. All
   queries (root/search/list/metadata/dist) must scope by the resolved repository, and metadata/list
   URLs must be generated with the repository prefix.
3. Filament: a RepositoryResource (list/create/edit; fields name, path, description, public toggle)
   and a repository select on the existing Package resource form + table filter.
4. Feature tests: same package name in two repositories resolves independently at / and /r/{path};
   unknown path 404s. Run tests.
```

## 6. Token authentication for Composer clients

**What Packistry does** (`app/Models/Token.php`, Sanctum-style, `Tokenable` contract,
`tokenScoped()` builders): Composer endpoints authenticate via HTTP Basic / bearer token. Tokens are
hashed at rest (only prefix + hash stored), have abilities `repository:read` / `repository:write`,
soft-delete for revocation, and `last_used_at`. Every Composer query runs through `tokenScoped()`,
which limits visible packages to public repositories plus whatever the token grants. Consumers
configure `composer config http-basic.<host> token <plain-token>`.

```text
In this Laravel app, protect the Composer endpoints with token auth:
1. Migration: access_tokens table (name, token_prefix, token hash sha256, abilities json,
   last_used_at, expires_at nullable, soft deletes) with a Token model; generate plain tokens once
   ("pp_" + 40 random chars), store only the hash, expose plaintext only at creation.
2. Middleware app/Http/Middleware/AuthenticateComposer.php: accept the token from HTTP Basic
   password (any username) or Authorization: Bearer; look up by hash; reject 401 with a JSON body
   telling the user to run `composer config http-basic.<host> token <token>`; touch last_used_at
   (throttled to once a minute). Read endpoints require ability repository:read, upload requires
   repository:write.
3. Apply to all composer.* routes. If a repositories table with a `public` flag exists, let
   unauthenticated requests through for public repositories only.
4. Filament: TokenResource — create (shows plaintext once in a modal), list with prefix,
   abilities, last_used_at, revoke (soft delete) action.
5. Feature tests: 401 without token, 200 with valid basic auth, revoked/expired tokens rejected,
   write ability enforced on upload. Verify a real `composer install` flow works against the test
   server if practical. Run tests.
```

## 7. Deploy tokens (machine access)

**What Packistry does** (`app/Models/DeployToken.php` + pivot tables `deploy_token_repository`,
`deploy_token_package`): deploy tokens are non-user principals for CI/CD. Each is scoped to a set of
repositories and/or individual packages (or unscoped = everything); `tokenScoped()` unions those
grants. They authenticate exactly like user tokens and appear in download stats.

```text
In this Laravel app (which already has Composer token auth — see the Token model and
AuthenticateComposer middleware), add machine deploy tokens with scoped access:
1. Model + migration: deploy_tokens (name, timestamps) with one access token each (reuse the
   token issuing/hashing mechanics), plus pivots deploy_token_package (and deploy_token_repository
   if a repositories table exists). An empty scope set means "all packages".
2. Extend the Composer auth layer: when the authenticated principal is a deploy token, scope every
   package query (root/search/list/metadata/dist) to its granted packages/repositories. Extract a
   query scope like Package::visibleTo($principal) used by all endpoints.
3. Filament: DeployTokenResource — create with multi-select of packages (and repositories),
   plaintext token shown once, revoke action, last_used_at column.
4. Feature tests: scoped token sees only granted packages in list/search/metadata and gets 404 on
   others' dist; unscoped token sees all. Run tests.
```

## 8. Personal access tokens (per-user)

**What Packistry does** (`PersonalTokenController`, `HasApiTokens` on `User`): users self-issue tokens
(name + abilities + optional expiry) from their profile; the token inherits the *user's* repository and
package access. Listing shows prefix + last used; delete revokes.

```text
In this Laravel app with Filament admin and Composer token auth, let each admin user issue personal
access tokens: tokens table rows are polymorphically owned (tokenable) by User or DeployToken if the
deploy-token feature exists — otherwise add a user_id owner to the existing tokens table. Build a
Filament page under the user menu ("API Tokens") where a user creates a token (name, read/write
abilities, optional expiry), sees it in plaintext once, lists their tokens with prefix/last_used_at,
and revokes them. Composer endpoints authenticate these tokens through the existing middleware and,
if per-user access scoping exists, restrict visibility to the owner's accessible packages. Feature
tests for issue/list/revoke and auth flow. Run tests.
```

## 9. Roles + granular permissions

**What Packistry does** (`app/Enums/Role.php`, `app/Enums/Permission.php`, `config/authorization.php`):
two roles (admin, user) mapped in config to a flat permission enum covering CRUD per domain
(repository_*, package_*, user_*, source_*, deploy_token_*, personal_token_*, authentication_source_*,
batch_*) plus `dashboard` and `unscoped` (bypasses row-level scoping). A `Gate::before`-style check
resolves `$user->can(Permission::X)` from the role → permission map. Simple, no DB permission tables.

```text
In this Laravel + Filament app, add config-driven role authorization:
1. app/Enums/Role.php (Admin, User) and app/Enums/Permission.php (string-backed cases:
   package_create/read/update/delete, version_read, token_manage, user_manage, source_manage,
   dashboard, unscoped — adjust to the domains that exist in app/Filament/Resources).
2. Migration: role string column on users defaulting to 'user'; the existing super admin seeder
   gets role admin.
3. config/authorization.php maps each role to a permission array; register a Gate::before or
   per-permission Gate definitions reading that map; add User::can support via a hasPermission()
   helper.
4. Enforce in Filament: each Resource's canViewAny/canCreate/canEdit/canDelete checks the matching
   permission; hide navigation items the user can't access; keep Filament login open to both roles.
5. Tests: role map resolves correctly; a 'user' role cannot access an admin-only resource page
   (HTTP test against the Filament route). Run tests.
```

## 10. Per-user repository/package access scoping

**What Packistry does** (`repository_user`, `package_user` pivots, `userScoped()` builders): non-admin
users are granted specific private repositories and/or individual packages. Every listing (API,
dashboard counts, download stats) funnels through `userScoped()`: public repos ∪ granted repos ∪
granted packages, with `unscoped` permission bypassing. Authentication sources can also auto-grant
package access (`authentication_source_package`).

```text
In this Laravel + Filament app (roles already exist), add row-level access for non-admin users:
1. Pivot migrations package_user (and repository_user if repositories exist) with unique indexes.
2. Package::visibleToUser(User) query scope: admins/unscoped see all; others see packages in public
   repositories plus explicitly granted packages/repositories.
3. Apply the scope in the Filament PackageResource table query, any version listings, and (if
   present) dashboard counts and download stats.
4. Filament: on the User form, multi-selects for granted packages/repositories; on the Package
   form, an inverse users multi-select.
5. Tests: granted user sees only their packages in the Filament table query; admin sees all. Run tests.
```

## 11. Provider webhooks (auto-sync on push)

**What Packistry does** (`app/Http/Controllers/Webhook/*`, `app/Sources/*/Event/*`): per-provider
endpoints `POST /incoming/{github|gitlab|gitea|bitbucket}/{sourceId}` validate signatures
(GitHub/Gitea: HMAC `X-Hub-Signature-256`; GitLab: secret token header) against the source's stored
secret. Push events resolve the package by `(source_id, provider_id)` and import just that tag/branch
(zipball download → CreateFromZip); delete events remove the corresponding version (and its archive).
`ping` returns 204; unknown events 422. Webhooks are auto-registered on the provider when a package is
onboarded (`Client::createWebhook`).

**Gap**: package-pipeline syncs by full re-scan only (manual/scheduled).

```text
In this Laravel app (private Composer registry; GitHub sync exists in
app/Services/PackageSynchronizer.php and app/Services/GitHub/GitHubClient.php), add GitHub webhook
ingestion:
1. Migration: add webhook_secret (encrypted cast) to packages — or to sources if a sources table
   exists.
2. Route POST /incoming/github/{package} (no CSRF, force JSON). Controller validates
   X-Hub-Signature-256 HMAC against the secret (hash_equals), then handles: ping -> 204;
   push of a tag -> sync only that tag (create/update the single PackageVersion, reusing the
   normalizer and archive logic from PackageSynchronizer rather than a full resync);
   push of a branch -> update that dev version; delete event -> remove the matching version and its
   stored archive. Unknown events -> 422. Record last_synced_at / sync_error as the full sync does.
3. Add a "webhook setup" section on the Filament Package page showing the payload URL + secret, and
   an action that calls the GitHub API to create the webhook automatically (POST /repos/{repo}/hooks
   with content_type json and the secret) using the package's stored token.
4. Feature tests with signed fake payloads: valid signature imports one version, bad signature 401,
   delete removes version, ping 204. Run tests.
```

## 12. Queued batch imports with progress

**What Packistry does** (`app/Jobs/*`, `PackageImportBatch`, `BatchController`): onboarding a package
dispatches a `Bus::batch` of `ImportBranches` + `ImportTags`; each lazily pages through provider refs
and fans out one `ImportImportable` job per ref (batch `allowFailures()`). The UI polls `/batches` to
show progress (total/processed/failed) and can prune finished batches. Result: importing a package with
hundreds of tags doesn't block a request and failures are per-version, not all-or-nothing.

**Gap**: `PackageSynchronizer::sync()` runs inline and one bad ref can fail the whole sync.

```text
In this Laravel app, move package syncing onto queued job batches:
1. Refactor app/Services/PackageSynchronizer.php so the per-reference work (fetch composer.json,
   normalize version, upsert PackageVersion, store archive) lives in a dedicated
   app/Jobs/ImportVersion.php job (Batchable, Queueable) that takes package id + ref data.
2. Add app/Jobs/DiscoverVersions.php that lists tags+branches via GitHubClient and adds one
   ImportVersion per ref to its batch; expose PackageSyncBatch::dispatchFor(Package) that creates a
   named Bus::batch([DiscoverVersions]) with allowFailures(), storing batch id on the package.
3. After the batch finishes, prune versions no longer present upstream (finally callback) and set
   last_synced_at / sync_error.
4. Wire the Filament "sync" action (or create one on PackageResource) to dispatch the batch and show
   batch progress on the package view (poll job_batches by stored id: processed/total/failed).
5. Ensure queue + batches tables exist (php artisan queue:batches-table if needed). Tests with
   Bus::fake and a small fake ref set exercising fan-out and failure isolation. Run tests.
```

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

```text
In this Laravel + Filament app, add download analytics:
1. Migration: downloads table (package_id FK, package_version_id FK nullable, ip string nullable,
   token identifier nullable, created_at indexed) + total_downloads unsignedBigInteger default 0 on
   packages and package_versions.
2. Fire an event from ComposerRepositoryController::dist() after a successful archive response; a
   queued listener inserts the Download row and increments both counters.
3. Artisan command downloads:recalculate to rebuild counters from the table.
4. Filament dashboard widgets: StatsOverview (package count, version count, downloads last 30 days)
   and a per-day downloads chart for the last 90 days (single grouped query on created_at date).
   Add a total_downloads column to the PackageResource table, sortable.
5. Tests: downloading dist creates a row and bumps counters; chart query groups correctly. Run tests.
```

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

```text
In this Laravel app (private Composer registry, Filament admin), add operational artisan commands
mirroring the admin UI, using laravel/prompts for interactive input with non-interactive option
flags for scripting: user:add (name, email, password, role) and user:reset-password;
package:add (name/repo URL/token) dispatching the existing sync; package:delete (with confirmation,
also deleting stored archives); token:add / token:revoke for Composer access tokens if that feature
exists; package:sync {name?} to trigger sync for one or all packages. Reuse existing
services/actions rather than duplicating logic, register in routes/console.php or via attributes,
and add a smoke test per command with artisan() test helpers. Run tests.
```

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
