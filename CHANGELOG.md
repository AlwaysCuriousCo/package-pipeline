# Changelog

Notable changes to Package Pipeline, written for the person upgrading a
deployment: what is new, what behaves differently, and what a release asks of
the operator. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and versions follow [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

The 0.x line reached [v0.9.4](https://github.com/AlwaysCuriousCo/package-pipeline/releases/tag/v0.9.4)
without a changelog, so what follows describes the registry as a whole rather
than only what changed since that tag. Entries an operator upgrading from 0.9.x
must act on are collected under **Upgrading from 0.9.x** at the end.

### Added

- **Public pages.** A package or a repository can publish a page anyone can read
  — no account, no token, no Composer. A package page shows the description, the
  repository's `package-page.md` or `README.md`, the install commands, the
  version history and, when switched on, a download for the latest release or
  for every version; a repository's landing page is served at the same URL its
  Composer endpoints hang off (`/` for the default repository) and lists the
  packages publishing pages of their own. Off until enabled, per package and per
  repository. A page on a private repository still describes the package but
  withholds the archives and the install commands, showing an access notice in
  their place. Markdown from a repository is rendered with raw HTML escaped and
  relative links resolved against the repository it came from, and images in it
  are re-served by the registry through the package's own credentials — which is
  what makes a private repository's screenshots, and its social card, render for
  a reader who has no access to it. Every page carries Open Graph and Twitter
  card tags, JSON-LD and a canonical URL, and `/sitemap.xml` and `/robots.txt`
  list them (`PAGE_SITEMAP=false` to publish neither). See
  [docs/public-pages.md](docs/public-pages.md).

- The [Composer v2 repository API](https://getcomposer.org/doc/05-repositories.md#composer):
  `packages.json`, `search.json`, `list.json`, per-package `p2` metadata, and
  `dist` zipballs. A consuming project needs one `repositories` entry and no
  per-package wiring.
- Multiple named repositories, mounted at `/r/{path}` alongside the default one
  at the root, so a deployment can serve more than one audience.
- Monorepo packages: a per-package **subdirectory**, so one repository URL can
  publish several packages. A subdirectory package's dist archive is cut out of
  the provider's whole-repository zipball and re-rooted, without unpacking it,
  so what Composer downloads is that directory alone. A push syncs every
  package published from the repository, and one webhook covers all of them.
  See [docs/monorepos.md](docs/monorepos.md).
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
- **Teams**: a group that holds package and repository grants, so onboarding is
  adding somebody to a team rather than re-granting what the last person was
  given. A user's effective access is their own grants plus their teams'; a
  user in no team is unaffected, and leaving a team takes back only what that
  team gave. See [docs/teams.md](docs/teams.md).
- A **Licenses** page reporting what the registry publishes under, which
  packages and versions carry each license, and — the number worth watching —
  how many versions declare none at all. Each version's declaration is kept in
  a column of its own, derived from its manifest.
- **Download a version's archive from the panel**, from the versions list on a
  package's page or from the version's own detail modal — the stored zip a
  Composer client would install, without configuring one. Scoped to the
  admin's own grants, and served straight off the dist disk — or as a redirect
  to it, on a disk that pre-signs its own URLs. It counts as a download like
  any other, in the counters and in the download history, carrying no token
  prefix because none was presented.
- **CycloneDX 1.6 SBOM export**, registry-wide or per package, from the panel
  and from `sbom:export`. One component per package version, streamed, and cut
  to the caller's own grants. See [docs/licensing.md](docs/licensing.md).
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
- Email notifications for the same announcements, off by default and turned on
  with `MAIL_ADMIN_NOTIFICATIONS=true` once a mailer is configured. Recipients
  are the panel users holding a role, and each of them can opt out under **Email
  notifications** on their profile page — the environment setting decides
  whether email happens at all, the profile toggle only ever narrows it. The
  bell is not on the toggle, so switching email off never leaves somebody with
  no way of hearing that a package stopped syncing. See [Emailing the
  announcements](docs/deployment.md#emailing-the-announcements).
- [Resend](https://resend.com) as an installed mail transport:
  `MAIL_MAILER=resend` and `RESEND_API_KEY` are the whole of the setup, with
  `MAIL_FROM_ADDRESS` on a domain verified in that account. `ses`, `postmark`
  and `smtp` remain configured and work unchanged. Mail stays on the `log`
  driver by default.
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
  webhooks** in the panel with a URL, a set of events, a scope and a shared
  secret.

  An endpoint can be confined to one Composer repository, and on a registry
  serving more than one audience it should be. A payload carries a private
  package name, the path its repository is mounted at and the VCS URL behind
  it — so an unscoped endpoint one team configured hears all of that about every
  other team. Left empty the scope is the whole registry, which is what a
  single-audience installation wants and what every endpoint means today.

  Deliveries are signed the way GitHub signs the ones this app receives —
  `X-Hub-Signature-256`, an HMAC-SHA256 over the raw body — so a receiver already
  written for a GitHub webhook needs no new code. Every delivery is queued and
  retried twice; a dead endpoint can never fail or delay a sync, and its last
  outcome is shown in the panel with a consecutive-failure count. **Send test
  delivery** posts a `ping` to prove the URL before a release depends on it. See
  [docs/outgoing-webhooks.md](docs/outgoing-webhooks.md).
- Download statistics export as CSV, per package or registry-wide, over a date
  range. Two reports: a summary (one row per version, with a count) and the
  detail (one row per download, with the credential that fetched it).

  Available from the packages table in the panel — scoped to the signed-in
  admin's own grants — and as `downloads:export`, which writes to a file or to
  stdout for a scheduled extract. Nothing holds more than one row in memory:
  `downloads` is the fastest-growing table in the schema, so rows are streamed
  from a cursor. See [docs/download-analytics.md](docs/download-analytics.md).
- A Prometheus scrape endpoint at `/metrics`: registry totals, sync health
  (failing, never-synced and stale packages, and how long since anything
  synced), queue depth on the database driver, failing outgoing webhook
  endpoints, and what the mirror cache holds when a repository mirrors. This is
  what `/up` cannot be — that endpoint proves the container boots and touches
  neither the session nor the database.

  **Off by default** (`METRICS_ENABLED`), because a Composer registry is
  routinely published to the internet and these numbers describe the
  installation to anyone who asks; while it is off the path answers 404.
  `METRICS_TOKEN` adds bearer authentication. Nothing is labelled with a package
  name, and nothing counts the `downloads` table. See
  [docs/metrics.md](docs/metrics.md).

### Changed

- An archive is named for the package everywhere it is handed over. The
  download is `widgets-1.4.0.zip` rather than `acme-widgets-1.4.0.zip` — the
  vendor is the same word on every archive a registry serves — and a dist disk
  that answers with its own pre-signed URL is now asked for that name too,
  where before it named the download after the stored object and handed over
  `019ff239-....zip`. Inside the zip, the single top-level directory is the
  package's own name (`widgets/`) instead of the provider's `owner-repo-sha/`.
  Composer discards that directory either way, and the `shasum` served beside
  an archive is still the hash of the bytes served, so nothing changes for a
  consuming client. Archives already stored are left alone; run
  `php artisan package:rebuild` to restate them.
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

- `archives:audit` refuses at the scale of a wrong disk. It used to decline only
  when the dist disk listed *nothing*, which one file defeated: a bucket
  repointed or restored from an older snapshot, plus a single package getting a
  tag at 03:00, and the 03:20 sweep cleared every other version in the registry
  unattended. It now refuses once more than a tenth of the versions it checked
  (and more than twenty of them) come up missing, and `--force` is the way past
  it. Versions of packages published by artifact upload are never cleared —
  nothing can re-sync them, so those columns are what a restore is done from.
  The clear also stops stamping `updated_at`, which was invalidating the `/p2`
  metadata of every affected package for every Composer client.
- The grant pivots are indexed in the direction they are read. Each had only its
  unique pair, and each is queried by the column that pair puts second — so
  `team_user`, `package_team`, `repository_team`, `package_user` and
  `repository_user` were being scanned on a path that runs once per metadata and
  once per dist request for a scoped client. `package_advisories` had no index on
  `package_id` at all, on the endpoint Composer 2.9 calls inside every
  `composer update`.
- Reversing the subdirectory migration refuses instead of failing partway
  through. It restored a unique index that cannot exist once any repository URL
  publishes more than one package, having already dropped the wider one — which
  on an engine without transactional DDL left `packages` with neither.
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

- A composer.json name is validated against Composer's grammar before a sync
  adopts it. The sync path had never checked, on the reasoning that the name
  came out of a file Composer had validated — but nothing puts that file through
  Composer on the way here, and it is written by whoever controls the
  repository. A package declaring `../mirror/...` stored its archive outside the
  prefix `archives:clean` sweeps and inside the one `mirror:prune` deletes from,
  after which `archives:audit` cleared the row: a nightly loop reachable by
  pushing a tag. `ArchiveStore` now refuses to write outside its own prefix as
  well, because Flysystem only objects to a path leaving the disk root, and one
  prefix leading into the other never does.
- Team grants were added inside `Package::scopeVisibleToUser` and
  `Repository::scopeVisibleToUser` — the single chokepoint every read in the app
  goes through — rather than beside them, so no surface can be out of step with
  the others. They confer the right to publish exactly as an equivalent personal
  grant does; a grant that meant one thing held personally and another held
  through a team would be a second, invisible kind of grant. Deploy tokens have
  no teams: they authenticate a machine, which cannot be a member of anything.
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

### Upgrading from 0.9.x

Everything here is something a deployment has to do or know; the rest of this
release looks after itself.

- **Take a database backup first, because this release is roll-forward only.**
  `migrate:rollback` is not a supported way back out of it and will not get you
  there. Several of these migrations are lossy in reverse by their nature —
  dropping the teams tables takes every grant those teams held, dropping
  `package_versions.license` takes what each version declared, and the
  lowercasing migration's `down()` is empty because the casing is precisely
  what it discarded. Worse, one of them *cannot* reverse at all once the
  feature it added is in use: the subdirectory migration widened a unique
  index, and the narrow one it would restore is by definition impossible to
  rebuild while any repository URL publishes more than one package. It now
  refuses with that explanation rather than failing partway through a schema
  change, but a rollback that reaches it has already unwound the migrations
  after it. Restoring the backup is the recovery path; plan the upgrade that
  way.
- **Run `php artisan migrate`.** Twenty-one migrations, all safe on a populated
  database, and none of them rewrites a row except the name normalization
  below. Two are worth knowing about: `notifications.data` becomes a json
  column, which is what stops the panel answering `500` on every page under
  PostgreSQL; and package names are lowercased, since a mixed-case name could
  never be fetched through `/p2` on a case-sensitive collation. Where two
  packages in one repository differ only in case, the migration renames
  neither — it notifies the admins, leaves a note in each `sync_error`, and
  logs them, rather than choosing which of the two to unpublish. That note is
  re-asserted by every later sync, so the packages stay flagged in the panel
  until somebody deletes one of each pair.
- **Check that `DIST_DISK` is case-sensitive**, which S3 and Linux are and a
  `local` disk on macOS or Windows is not. `archives:clean` and
  `archives:audit` match the disk listing against `archive_path` exactly, and a
  case-insensitive disk keeps the casing a directory was first created under —
  so after the name lowercasing above, a renamed package's archives are listed
  under a path no row holds and read as orphans. Only development machines are
  affected in practice; run `archives:clean --dry-run` once if yours is one.
  See [The dist disk has to be case-sensitive](docs/deployment.md#the-dist-disk-has-to-be-case-sensitive).
- **Re-seed the permissions** with `php artisan db:seed --force`. This release
  adds panel resources — activity, teams and outgoing webhooks — and Shield
  denies what has no permission row, including to a super admin.
- **Restart queue workers with `--timeout=310`.** The queue's `retry_after` is
  now 330 seconds, above the longest job's own timeout. Left at the old 90, a
  worker still streaming a large archive is handed the same job a second time.
- **Run the scheduler.** It is no longer optional: the hourly `packages:sync`
  is what retries a package whose imports partly failed and what reaches a
  package no webhook covers, and the nightly commands are what stop archives,
  notifications and job batches accumulating without bound. See
  [docs/deployment.md](docs/deployment.md).
- **Node 22.19+ and `npm run build` are required**, and a deploy that skips the
  build serves 500s from every admin page. An earlier revision of this release
  removed the front-end build on the grounds that none was being served, which
  was true and incomplete: Filament's shipped stylesheet carries only its own
  `fi-*` classes and no Tailwind utility layer, so the panel views that use
  utility classes had been rendering unstyled. The panel now compiles its own
  theme — Filament's stylesheet rebuilt from source, plus a utility layer
  generated from `app/Filament` and `resources/views/filament` — registered with
  `->viteTheme()`. `composer run setup` runs the build; CI and deploy pipelines
  need `npm ci && npm run build` added.
- **`public/robots.txt` has been removed**, so that `/robots.txt` can be
  answered by the app — it now points crawlers at `/sitemap.xml` and keeps them
  off `/admin`, `/p2/` and `/dist/`, or answers a blanket disallow when
  `PAGE_SITEMAP=false`. A deployment that restores the static file shadows the
  route, and the setting then does nothing.
- Nothing new is enabled by default. Public pages, upstream mirroring, the
  Prometheus endpoint, outgoing webhooks and vendor reservations all start off,
  and a registry that ignores them behaves as it did before. In particular the
  registry root goes on redirecting to `/admin/login` until a page is published
  for the default repository.

[Unreleased]: https://github.com/AlwaysCuriousCo/package-pipeline/compare/v0.9.4...HEAD
