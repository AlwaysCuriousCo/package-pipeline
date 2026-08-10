# Changelog

Notable changes to Package Pipeline, written for the person upgrading a
deployment: what is new, what behaves differently, and what a release asks of
the operator. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Nothing has been tagged yet, so everything below is the first release taking
shape rather than a delta from a previous one.

### Added

- The [Composer v2 repository API](https://getcomposer.org/doc/05-repositories.md#composer):
  `packages.json`, `search.json`, `list.json`, per-package `p2` metadata, and
  `dist` zipballs. A consuming project needs one `repositories` entry and no
  per-package wiring.
- Multiple named repositories, mounted at `/r/{path}` alongside the default one
  at the root, so a deployment can serve more than one audience.
- Package syncing from a GitHub repository's tags and branches, run as queued
  job batches with live progress on the package's page. `package:rebuild`
  re-imports every version trusting nothing already stored — the recovery path
  for corrupted archives and metadata drift.
- Version archives fetched at sync time and stored on a local or S3 disk
  (`DIST_DISK`), checked against their published `shasum`, so GitHub is never in
  a download path. `archives:clean` prunes the files re-syncs leave behind.
  A disk that can pre-sign URLs serves `dist` downloads itself — the client is
  redirected to a short-lived URL instead of the app streaming the zip — so the
  bucket has to be reachable from wherever `composer install` runs.
- Sources: GitHub App installations that authenticate syncs with hourly,
  org-owned tokens scoped to the repositories chosen at install time. Packages
  can be onboarded straight from a source's project list.
- GitHub and GitLab webhooks, so a package syncs as soon as a tag or branch
  moves. The GitHub App's account-wide webhook covers every installed
  repository; per-repository hooks are the fallback for packages with no source.
- Access tokens for Composer clients and scoped deploy tokens for machines,
  with package visibility scoped per panel user.
- Reserved vendor prefixes per Composer repository, as a dependency-confusion
  defence: only the owning repository may introduce names under a reserved
  vendor, enforced on the create wizard, the API, `package:add`, artifact
  uploads and a sync adopting a repository's declared name. Existing packages
  are never broken by a later reservation. The consumer half — the Composer
  configuration that makes this registry canonical for your vendors — is in
  [docs/dependency-confusion.md](docs/dependency-confusion.md), and matters
  more than the server half.
- Upstream mirroring: a Composer repository can be given one or more upstreams
  — packagist.org, a corporate proxy, another private registry — and will then
  serve packages it does not publish, caching the metadata and the release zip
  on your own infrastructure. One URL resolves a consuming project's whole
  dependency graph, and a build stops depending on packagist.org and GitHub
  being up. On-demand only: nothing is fetched until a client asks for it.
  Off until an upstream is added, and an installation with none behaves exactly
  as before.

  A local package always wins, unconditionally — a name published anywhere in
  this installation, or under a reserved vendor, is never served from an
  upstream, visible to the caller or not. **Reserve your vendor prefixes before
  enabling mirroring**: an unreserved vendor is mirrorable, which is the whole
  hole [docs/dependency-confusion.md](docs/dependency-confusion.md) is about.

  Archives are verified against the `shasum` the upstream published before
  being stored, `composer audit` is passed through for mirrored names so
  mirroring does not silently switch auditing off for most of a graph, and
  `mirror:prune` (nightly, retention measured on last use) is the only thing
  that bounds the disk. See [docs/mirroring.md](docs/mirroring.md).
- Artifact uploads from CI (`POST /upload/{vendor}/{package}`), for packages
  that are built rather than tagged.
- A versioned JSON management API at `/api/v1` — list and show packages with
  their versions, create one, trigger a sync, delete one, list repositories —
  so CI and provisioning scripts need neither the panel nor SSH. It reuses the
  existing access tokens under their own `api:read`, `api:write` and
  `api:delete` abilities, so a credential that installs packages cannot
  administer or delete them. See [docs/api.md](docs/api.md).
- Download tracking, surfaced as a registry-wide chart, a per-package chart, and
  a totals widget on the dashboard; `downloads:recalculate` rebuilds the
  denormalized counters from the raw rows.
- A release heatmap per package, and version status icons that distinguish a
  version that is served from one that is merely recorded.
- Runtime-configurable SSO login providers — one button per active
  authentication source on the panel's login screen.
- Role-based admin access through Filament Shield, with permissions seeded from
  the panel's own resources so a fresh database gets them from `db:seed`.
- Slack notifications for published releases and failed syncs, alongside the
  panel's own notification bell.
- Operational commands, so a deployment can be provisioned without a browser:
  `admin:create`, `user:add`, `user:reset-password`, `package:add`,
  `package:delete`, `package:rebuild`, `packages:sync`, `token:add`,
  `token:revoke`, `archives:clean`, `mirror:prune`, and
  `downloads:recalculate`.
- CI across PHP 8.3, 8.4 and 8.5, with code style (Pint) and static analysis
  (PHPStan via Larastan, level 6) enforced as their own job.

- Outgoing webhooks: an HTTP endpoint of your own can be told when a version
  publishes, a sync fails, or a package is abandoned — for a deploy pipeline, an
  incident tracker, or a chat tool that is not Slack. Configured under **Outgoing
  webhooks** in the panel with a URL, a set of events and a shared secret.

  Deliveries are signed the way GitHub signs the ones this app receives —
  `X-Hub-Signature-256`, an HMAC-SHA256 over the raw body — so a receiver already
  written for a GitHub webhook needs no new code. Every delivery is queued and
  retried twice; a dead endpoint can never fail or delay a sync, and its last
  outcome is shown in the panel with a consecutive-failure count. **Send test
  delivery** posts a `ping` to prove the URL before a release depends on it. See
  [docs/outgoing-webhooks.md](docs/outgoing-webhooks.md).

### Changed

- Per-package `/p2` metadata answers conditional requests. Every response
  carries `Last-Modified`, a weak `ETag` and `Cache-Control: no-cache, private`,
  and a Composer client whose copy is current is answered `304` after a single
  aggregate over the version rows — no rows read, no payload built, no body
  sent. `packages.json`, `list.json` and `search.json` deliberately keep no
  validators: the first has no work for a `304` to skip, and the other two
  answer a set of packages chosen by the caller's grants, which cannot be
  fingerprinted more cheaply than it can be answered.
- The rendered `/p2` payload is cached under that same fingerprint, so a
  package with hundreds of versions is decoded and re-serialised once per
  change rather than once per consumer per request. Entries supersede
  themselves — nothing has to remember to clear them — and `METADATA_CACHE_DAYS`
  and `METADATA_CACHE_MAX_KB` bound how long and how large. Who may see a
  package is still decided per request; only the bytes are cached.
- `/p2` metadata is served in Packagist's minified format
  (`"minified": "composer/2.0"`), where each version carries only what differs
  from the one before it. Composer expands it on arrival — with the same
  library that produces it here — so nothing changes for a client except how
  much of it there is to download. A document without the key is read as
  already expanded, which is what these responses were until now.
- Versions are normalized once on the way in (`v1.2.3` → `1.2.3`, branches to
  Packagist-style dev versions) and carry an indexed sort key, so `1.10.0`
  ranks above `1.9.0` and a release above its own release candidates — in the
  panel and in what `/p2` serves.
- VCS access sits behind provider client contracts; GitHub and GitLab differ
  only in their implementations, not in the syncing code above them.
- Every outbound HTTP call carries a timeout sized to what that call is for,
  rather than inheriting a single global one.
- The queue's `retry_after` is held above the longest job timeout, so a version
  import streaming a large archive is never handed to a second worker while the
  first is still downloading it.
- There is no front-end build. The only pages the app serves are Filament's,
  and Filament publishes its own assets, so Node, npm and Vite are gone from
  setup entirely.

### Fixed

- A sync batch left behind by a lost worker is cancelled before its replacement
  starts, instead of letting stale import jobs race the new sync.
- Recording a download no longer stamps `updated_at` on the package and version
  rows. Eloquent does that on an increment by default, which made a package's
  "Last synced" read as "last downloaded" — and would have made the busiest
  packages in the registry invalidate their own metadata on every zip served.
- A download whose version was pruned before the queued listener recorded it
  still counts. The listener wrote the ids captured when the archive went out,
  and a stale one failed the job on a foreign key — losing the download and
  leaving a `failed_jobs` row behind for every one of them.
- The notifications table stores its payload as JSON. On PostgreSQL the panel's
  notification bell filters those rows with an operator a text column has no
  meaning for, so every page of the admin panel answered 500. MySQL and SQLite
  tolerated the old column, which is why only one deployment target saw it.
- Archive presence is verified on the dist disk rather than trusted from the
  database, so a re-sync actually rebuilds a zip that storage lost, and a dist
  request falls through to a sibling row whose file is still there.
- Connecting a source to an account another source already holds is refused
  with a clear error, and a GitHub reconnect whose installation belongs to a
  different owner no longer silently reassigns the source.
- The sync uniqueness lock is released when a dispatch throws, instead of
  leaving every sync looking already-queued for an hour.
- Renaming a package fails with a clear error when the new `composer.json` name
  would collide with another package in the same repository.
- Package names are stored lowercase, which is the only case Composer ever asks
  in. A package whose `composer.json` declared `Acme/Widgets` was stored exactly
  that way and was then unfetchable through its own `p2` and `dist` endpoints on
  SQLite and PostgreSQL, whose collations are case-sensitive; MySQL's default
  collation hid it entirely, so the bug only existed on the engines a real
  deployment is most likely to use. The two endpoints also fold the name in the
  URL now, so a hand-typed or non-Composer request resolves the same package.

  A migration normalizes the names already stored. It will not rename a package
  whose lowercase name is already taken by another package in the same
  repository — a pair that can only exist on a case-sensitive engine, where the
  mixed-case half has never served anything. Those are left untouched, noted on
  the package in the panel and named in the deploy output and the log; delete
  whichever of the two is obsolete.

### Security

- Password setup links are sealed: address, token and expiry are encrypted into
  a single opaque path segment, and the link is single-use and valid for five
  minutes — safe to print into a deploy provider's command log.
- SSO sign-in adopts an existing account only when the provider has verified the
  address and the source's domain allowlist passes. Previously any source
  asserting an admin's email could sign in as them.
- Dist downloads are recorded without the requesting IP address. Nothing ever
  read it, and keeping one per download forever is a retention liability a
  self-hosted registry should not take on by default. The migration drops the
  column, and with it the addresses already collected.
- Deleting a user revokes their access tokens, and the Composer middleware
  treats a token whose principal no longer resolves as spent rather than as an
  anonymous caller with public access.
- Artifact uploads are bounded at both ends. The endpoint refuses an archive
  larger than `ARTIFACT_UPLOAD_MAX_MB` (100 MB by default) rather than
  inheriting whatever `upload_max_filesize` a deployment happens to allow, and a
  zip whose `composer.json` declares more than a megabyte is refused before it
  is decompressed — a few-kilobyte archive could otherwise inflate that one
  entry into gigabytes of a worker's memory.
- Rate limits on the surfaces a stranger can reach. Failed Composer
  authentications are counted per address and answered `429` once an address has
  spent thirty in a minute; webhook deliveries are limited per repository and per
  address; SSO and artifact uploads have ceilings of their own. Successful
  Composer traffic is deliberately not throttled — one `composer install` fans
  out a request per package, and a CI fleet arrives from a single address — and
  uploads are keyed by the token rather than the address for the same reason.

[Unreleased]: https://github.com/AlwaysCuriousCo/package-pipeline/commits/main
