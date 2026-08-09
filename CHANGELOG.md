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
- Sources: GitHub App installations that authenticate syncs with hourly,
  org-owned tokens scoped to the repositories chosen at install time. Packages
  can be onboarded straight from a source's project list.
- GitHub and GitLab webhooks, so a package syncs as soon as a tag or branch
  moves. The GitHub App's account-wide webhook covers every installed
  repository; per-repository hooks are the fallback for packages with no source.
- Access tokens for Composer clients and scoped deploy tokens for machines,
  with package visibility scoped per panel user.
- Artifact uploads from CI (`POST /upload/{vendor}/{package}`), for packages
  that are built rather than tagged.
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
  `token:revoke`, `archives:clean`, and `downloads:recalculate`.
- CI across PHP 8.3, 8.4 and 8.5, with code style (Pint) and static analysis
  (PHPStan via Larastan, level 6) enforced as their own job.

### Changed

- Per-package `/p2` metadata answers conditional requests. Every response
  carries `Last-Modified`, a weak `ETag` and `Cache-Control: no-cache, private`,
  and a Composer client whose copy is current is answered `304` after a single
  aggregate over the version rows — no rows read, no payload built, no body
  sent. `packages.json`, `list.json` and `search.json` deliberately keep no
  validators: the first has no work for a `304` to skip, and the other two
  answer a set of packages chosen by the caller's grants, which cannot be
  fingerprinted more cheaply than it can be answered.
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
