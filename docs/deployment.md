# Running this in production

The [README's deployment section](../README.md#deploying) is the checklist: run
a worker, run the scheduler, seed the permissions, create an admin, point
`DIST_DISK` somewhere shared. This page is the reasoning behind the parts of
that which are easy to get wrong, plus the two things a checklist can't cover —
what to back up, and what to do when a restore comes back inconsistent.

## `APP_URL` is what the registry publishes

Set it, and set it to the address consumers actually reach. Every `dist` URL in
every `/p2` document is built from it, so a wrong value produces metadata that
resolves and archives that do not.

It is deliberately *not* taken from the request's `Host` header. TLS terminates
at a load balancer, so the app trusts every proxy — which makes `Host` and
`X-Forwarded-Host` inputs an anonymous caller fully controls. Those headers
would otherwise reach the ETag that keys the rendered-metadata cache, so a loop
varying them could fill that cache with entries nobody will ever read, and hand
a consumer's Composer archive URLs on a host of its choosing.

Moving a live registry to a new address is therefore a change clients cannot
see in a `Last-Modified` — a URL has nowhere to go in a date. Bump
`ComposerRepositoryController::REVISION_EPOCH` (and `MirrorService`'s) in the
same deploy and every client refetches once.

## Recommended drivers

The defaults are `QUEUE_CONNECTION=database`, `CACHE_STORE=database`,
`SESSION_DRIVER=database`, and SQLite underneath all three. That is a
deliberate choice for a registry you can clone and run in a minute, and it is
genuinely fine for one container serving a handful of packages.

It stops being fine sooner than you'd think, for one specific reason: **a sync
fans out a job per ref.** Syncing a package with two hundred tags writes two
hundred `jobs` rows, which are then polled, locked, and deleted — and every one
of those operations is a write. Meanwhile the same database is answering
`/p2` requests, storing rendered metadata payloads, holding the scheduler's
locks and recording downloads. On SQLite there is exactly one writer, so all of
that queues up behind itself; on MySQL or Postgres it merely competes.

For anything beyond a single small deployment:

| | Set to | Why |
| --- | --- | --- |
| `QUEUE_CONNECTION` | `redis` | Takes the fan-out off the database entirely. This is the single biggest change. |
| `CACHE_STORE` | `redis` | The cache holds the metadata payloads, the conditional-request ETags for ref listings, and the locks below. All of it is hot, none of it is worth a database round trip. |
| `SESSION_DRIVER` | `database` is fine | Only the admin panel has sessions — the Composer endpoints run outside the session middleware, so no `composer install` ever writes one. Few and small; there is little to win here. |
| `DB_CONNECTION` | `mysql` / `pgsql` | Any deployment with more than one app container needs a database they can share anyway. |

Whatever you pick, keep `REDIS_QUEUE_RETRY_AFTER` (or `DB_QUEUE_RETRY_AFTER`)
above the longest job timeout — 300 seconds, for a version import streaming a
large archive. Below it, the queue decides a still-running import was abandoned
and hands it to a second worker, which downloads and stores the same archive
again. The default of 330 already accounts for this; it is only ever raised.

### The cache store is not just a cache

This one deserves its own heading because the failure is silent.

Every scheduled task runs `onOneServer()` and `withoutOverlapping()`, and each
package's sync carries a uniqueness lock of its own. All three are entries in
the cache store, and none of them errors when that store cannot hold them — they
simply stop being locks.

`database` and `redis` both qualify. The other two do not, and they fail
differently:

| | What its locks are | Defeated on |
| --- | --- | --- |
| `file` | files on one container's disk | **two or more containers** — each holds its own set |
| `array` | one PHP process's memory | **every deployment, including one container** |

`array` is the one worth reading twice. `schedule:run` is a fresh process every
minute, so a lock it takes is gone before the next tick asks about it — there is
no second container involved and nothing shared to begin with. A single small
registry, the deployment least likely to be suspected, has no overlap protection
at all.

What that costs is not a duplicated sweep. Two rebuilds of one package run at
once, and each finishes by pruning the versions the other just wrote — the loser
deletes the winner's rows. On three containers with `file` it is also three
hourly `packages:sync` fan-outs and three `archives:clean` runs racing each other
over the same disk.

So `CACHE_STORE=file` on more than one container, or `CACHE_STORE=array`
anywhere, is not a performance choice. It is a correctness bug that looks like
everything working.

`php artisan about` says which of the three you have, under **Registry →
Scheduler locking**. It is worth checking on a deployment nobody has looked at
in a while, because nothing else will ever mention it.

## The worker and the scheduler need a supervisor

Both are long-running foreground processes, and **neither restarts itself**.
Started by hand in a shell, or as a bare `ExecStart` with no restart policy,
they are one exit away from a registry that looks perfectly healthy and syncs
nothing: `/up` still answers, `/p2` still serves, and panel-triggered syncs
simply queue forever. That is the "dead queue worker" in the table further
down, and it is the most common way this deployment goes wrong.

`queue:work` is *designed* to exit — that is not a failure mode, it is the
supervision contract. It stops on its memory ceiling, on `--max-time`, on
`queue:restart` during a deploy, and on `SIGTERM` from an orchestrator. Every
one of those expects something to start it again.

**systemd**, one unit per worker, `Restart=always`:

```ini
[Unit]
Description=package-pipeline queue worker
After=network.target

[Service]
User=www-data
WorkingDirectory=/srv/package-pipeline
ExecStart=/usr/bin/php artisan queue:work --timeout=310 --memory=256 --max-time=3600
Restart=always
RestartSec=2
# Longer than the longest job, so a deploy lets an import finish instead of
# killing it halfway through storing an archive.
TimeoutStopSec=330
KillSignal=SIGTERM

[Install]
WantedBy=multi-user.target
```

**Supervisor**, if that is what the host already runs:

```ini
[program:package-pipeline-worker]
command=php /srv/package-pipeline/artisan queue:work --timeout=310 --memory=256 --max-time=3600
user=www-data
autostart=true
autorestart=true
numprocs=1
stopwaitsecs=330
stopsignal=TERM
stdout_logfile=/var/log/package-pipeline-worker.log
```

**Containers**: one container per process, `restart: unless-stopped` (or a
Deployment of its own on Kubernetes), and `terminationGracePeriodSeconds`
at least as long as `TimeoutStopSec` above. Do not run the worker in the same
container as the web server under a process manager you did not configure —
that is how a worker ends up dead with nothing watching.

Reading the flags:

- **`--timeout=310`** is the per-job ceiling for any job that does not set its
  own, and the backstop for the ones that do. It belongs between the longest
  job's own timeout (300 seconds, for a version import streaming a large
  archive) and the connection's `retry_after` (330); see the README for what
  goes wrong on either side of that window.
- **`--memory=256`** is the ceiling in MB, and it is the flag most likely to be
  missing. It is checked between jobs against the whole PHP process, not
  against the job, so the framework's default of 128 is a budget for a worker
  doing rather less than this one does — an import holds a version's archive
  while it stores it. Set it to what the host can spare and let the supervisor
  restart the process when it is reached: the exit is the design, and it is
  only a problem if nothing is watching.
- **`--max-time=3600`** retires the process every hour regardless. Long-lived
  PHP accumulates, and a scheduled restart is cheaper than working out which
  library is responsible.

Retries are not a worker flag here: every job in this app declares its own
`$tries` and `$backoff`, so `--tries` on the command line would only change the
handful that do not.

**Restart the workers on every deploy.** A worker holds the code it booted
with, so one started before a release keeps running the old classes against the
new database until something reminds it. `php artisan queue:restart` at the end
of the deploy tells every worker to finish its current job and exit; the
supervisor brings them back on the new release.

**The scheduler** is the same problem with a different shape. A
`* * * * * php artisan schedule:run` cron entry needs no supervision because
cron is the supervisor — that is the option to prefer on a normal host. Only
reach for `schedule:work` where there is no cron (a container image, typically),
and then give it the same `Restart=always` treatment as the worker, because it
is a foreground process that will not come back on its own either.

## Scaling out

**Stateless:** the app containers. Once `DIST_DISK` points at shared storage,
nothing in the request path is local — a container can be replaced mid-deploy
without losing anything.

**Not stateless, and shared by all of them:** the database, the dist disk, the
cache store (see above), and the queue.

**Queue workers** scale horizontally as far as you like. Their concurrency
guards are locks in the cache store, so they hold as long as that store is
shared.

**The scheduler** runs once. Either one container runs it, or every container
does and `onOneServer()` sorts it out — which, again, is only true with a
shared lock-capable cache.

### `DIST_DISK=s3` is required for more than one container

Not recommended — required. Version archives are written by whichever container
ran the import and read by whichever container answers the download. With a
local disk, an archive stored by container A simply is not there when container
B is asked for it, and `/dist` answers 404 for a version `/p2` is still
advertising.

The sharper edge is `archives:audit`. It compares the version rows against the
disk it can see and clears the rows whose archive is missing. Run against a
container holding only its own slice of a local disk, every archive the *other*
containers stored looks lost.

What stops that being data loss is that the command refuses at scale: once more
than ten percent of the versions it checked have no archive — and more than
twenty of them — it declines to act and says so, because losing that share at
once is what a wrong disk looks like, not what a registry losing files looks
like. So the split-disk deployment above produces a nightly failure with an
explanation rather than a nightly wipe. Below that threshold it still clears,
which is the point: a handful of genuinely lost objects is exactly what it is
for. `--force` is how an operator who really did lose the bucket gets through
it, and `--dry-run` prints the list without touching anything.

Two things it will never clear, for the same reason: a version of a package
published by artifact upload has no repository to be re-synced from, so the
`archive_path` and `shasum` are not a cache that can be rebuilt — they are the
record of which object held those bytes and what they hashed to, which is what
a restore is done with. Those are reported and left alone.

### The dist disk has to be case-sensitive

S3 and Linux filesystems are, so a production deployment is already fine and
nothing here needs doing. It is worth stating anyway, because the two
deployments where it is *not* true — `DIST_DISK=local` on macOS or Windows, and
a dist disk on SMB or NFS mounted case-insensitively — fail in a way nobody
would connect back to a filesystem setting.

`archives:clean` and `archives:audit` both work by listing the disk and matching
what comes back against `package_versions.archive_path`, string for string. That
holds as long as the disk reports a path back exactly as it was written. A
case-insensitive one does not: it keeps whatever casing a directory was *first*
created with. This release lowercased stored package names, so a package once
held under `packages/Acme/Widgets/` has its new archives written to
`packages/acme/widgets/` — and a case-insensitive filesystem quietly puts them
in the old directory and goes on reporting the old spelling.

The row and the listing then disagree about every one of those files, and both
commands read the disagreement as loss: `archives:clean` sees archives nothing
references and deletes live ones, `archives:audit` sees versions whose archive
is gone and clears `archive_path`. Run `archives:clean --dry-run` after
upgrading if the dist disk is local on a developer machine; a list of orphans
that looks like most of the registry is this, not garbage.

Neither command normalizes the comparison, deliberately. On the disks that
matter, `Widgets.zip` and `widgets.zip` are two objects, and folding them
together to accommodate the filesystems that cannot tell them apart would put
every correct deployment one collision away from deleting the wrong file.

### Dist redirects and where `composer install` runs

When the dist disk can pre-sign URLs (S3 and compatible services), `/dist/...`
answers a redirect to a short-lived URL — five minutes — instead of streaming
the zip through PHP. That is the right default: it takes the whole transfer off
the app.

It also means **the bucket's endpoint has to resolve and be reachable from
wherever `composer install` runs**, which is not the same place the app runs.
A MinIO service name on the app's internal network, or an S3 endpoint reachable
only inside the VPC, gives you a registry that works perfectly from the app's
own shell and fails for every CI runner and every laptop.

If that is your situation, either publish a resolvable address for the bucket
(`AWS_URL`), or keep the dist disk on something that cannot pre-sign, in which
case the app streams the bytes itself and only the app's own reachability
matters.

## Health and monitoring

`/up` is Laravel's health endpoint. It is worth being precise about it, because
it is the thing a load balancer will be pointed at:

- **What it proves:** the container is up, PHP is running, the framework boots,
  configuration and `APP_KEY` load. It runs with no middleware at all — no
  session, no database touched.
- **What it does not prove:** that the database is reachable, that a queue
  worker exists, that the scheduler is running, that the dist disk is
  reachable, or that any source can still authenticate.

That is a reasonable liveness probe and a poor readiness probe. It will report
a perfectly healthy container that has not synced anything in a week.

**`/metrics` is where that is answered.** A Prometheus scrape endpoint covering
registry totals, sync health, queue depth and the mirror cache — off by default,
one line to enable, optionally behind a bearer token. It is the mechanised
version of most of the table below, and
[docs/metrics.md](metrics.md) has the alert expressions.

For the things neither covers, or if you would rather watch by hand:

| Watch | How |
| --- | --- |
| A dead queue worker | Queue depth, or the simpler tell: syncs that start in the panel and never finish. `php artisan queue:failed` lists what gave up. If this is ever the answer, the worker is not supervised — see [above](#the-worker-and-the-scheduler-need-a-supervisor). |
| Failed syncs | They notify — the panel's bell and Slack, once a job has spent its retries. Configure `SLACK_BOT_USER_OAUTH_TOKEN` and you will hear about them without looking. |
| Dist disk drift | `php artisan archives:audit --dry-run` reports it without changing anything. Cheap enough to run from a monitor. |
| A source that stopped authenticating | The **Sources** table shows the connection state and the last error. A GitLab token that expired looks exactly like this. |
| The scheduler | `php artisan schedule:list` shows what should be running and when it last did. |

## Backup and restore

The registry keeps one dataset in two places: the **database** holds the
version rows, each carrying an `archive_path` and a `shasum`, and the **dist
disk** holds the archives those rows point at. Back the two up at different
moments and the restore is inconsistent — but not symmetrically, and that
asymmetry is what decides the ordering.

- **A row whose archive is missing** is the bad direction. `/p2` keeps
  advertising the version, `/dist` answers 404, and a consumer who pinned that
  version fails mid-install.
- **An archive whose row is missing** is harmless. It is an orphan, it costs
  disk, and `archives:clean` removes it.

So: **snapshot the dist disk first, then the database.** Anything written in
between lands on the disk without a row yet — the harmless direction. Do it the
other way round and every version imported during the gap comes back as a
promise the registry cannot keep.

Restore in the same order, disk before database, for the same reason.

### What to back up

| | Why |
| --- | --- |
| The database | Everything else derives from it: packages, versions, repositories, tokens, grants, sources, download counts. |
| The dist disk, `packages/` prefix | The archives themselves. Recoverable by re-syncing, but only while the upstream repositories still exist. |
| **`APP_KEY`** | The one people forget. Source tokens, per-package tokens, webhook secrets and SSO client secrets are all encrypted with it. Restore the database without the key and every source and every webhook is dead — and dead *quietly*, as decryption failures rather than as anything that looks like a configuration problem. |
| `.env` and the GitHub App private key | Not derivable from anything else. |

Not worth backing up: the cache store (metadata payloads and ref-listing ETags
rebuild themselves on demand), the queue tables (an interrupted sync is picked
up by the hourly schedule), and sessions.

### Repairing drift after a partial restore

Three commands cover the two directions of inconsistency. Run them once the
restore has settled — both archive commands deliberately ignore anything
touched in the last hour, because an import writes its archive inside the
transaction that saves the row, and a file that recent is indistinguishable
from an orphan.

**1. Find rows whose archive is gone.**

```bash
php artisan archives:audit --dry-run
```

Read the output before going further, because this is the one situation the
command is built to refuse. A misconfigured disk and genuine archive loss look
identical from here, so `archives:audit` declines to clear anything when the
disk lists no files at all, and equally when more than ten percent of the
versions it checked (and more than twenty of them) come up missing. A restore
that landed in the wrong bucket, or that is only half finished, trips the
second of those. The dry run is where you find out which of the two you have.

**2. Clear them.**

```bash
php artisan archives:audit --force
```

This nulls `archive_path` and `shasum` on the affected rows, which is all it
takes to make a version look unfinished. Nothing is deleted and no credential
is needed; the repair itself is the sync's job. `--force` is what says you have
read the dry run and the loss is real — drop it if the list was short enough
that the command was going to act anyway.

Versions of packages published by artifact upload are reported and skipped,
with or without `--force`. There is no repository to re-sync them from, so
their `archive_path` and `shasum` are the record of what to restore and what it
should hash to. Restore those files from the backup by hand, or delete the
versions; do not clear the rows first.

**3. Re-download.**

```bash
php artisan packages:sync
```

Every cleared version is fetched from its source again. This is the step that
needs the source credentials to still work — which is why `APP_KEY` is on the
backup list above.

**4. Clean the other direction.**

```bash
php artisan archives:clean --dry-run
php artisan archives:clean
```

Archives the restored database no longer references. Harmless to leave, but
this is the moment they are easiest to identify.

If a package's upstream repository no longer exists, step 3 cannot bring its
versions back and step 2 has already stopped the registry serving them. That is
the honest outcome — the archive really is gone — but it is irreversible, so
the dry run in step 1 is the one to actually read.
