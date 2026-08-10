# Prometheus metrics

`/metrics` answers the questions `/up` cannot. That endpoint proves the
container boots — it runs with no middleware and touches neither the session nor
the database, which makes it a liveness probe and nothing more. It will report a
perfectly healthy container that has not synced anything in a week.

This is where "has it synced anything in a week" is answered.

## Turning it on

**It is off by default**, and that is a decision rather than caution. Metrics
endpoints are conventionally unauthenticated because they conventionally sit on
an internal network. A Composer registry does not: it is routinely published to
the internet so that CI can reach it. An on-by-default endpoint would tell any
passer-by how many private packages exist, how much traffic they get and whether
the queue is stuck — and it would do that to every existing installation, on
upgrade, silently.

So enabling it is one line:

```dotenv
METRICS_ENABLED=true
```

While it is off, `/metrics` answers **404**, not 403. There is nothing there,
and telling a stranger that there is something they may not have is a worse
answer than saying nothing.

### Authentication

`METRICS_TOKEN` is optional and is a plain bearer token:

```dotenv
METRICS_TOKEN=a-long-random-string
```

```yaml
scrape_configs:
  - job_name: package-pipeline
    static_configs:
      - targets: ['registry.internal:443']
    scheme: https
    authorization:
      credentials: a-long-random-string
```

Leave it empty and the endpoint is open to anyone who can reach the path. That
is the right answer when the port genuinely is not routable — a sidecar, a
private network, a path your load balancer refuses from outside — and the wrong
one whenever there is any doubt. If you cannot state which of those you are in,
set the token.

The comparison is constant-time, so a wrong token leaks nothing about the right
one.

## What is exposed

Everything is prefixed `package_pipeline_`. Nothing is labelled with a package
name — see [below](#why-there-are-no-per-package-series).

| Series | Type | |
| --- | --- | --- |
| `up` | gauge | Always 1, labelled with the app version. Its *absence* is the signal. |
| `repositories` | gauge | Composer repositories served. |
| `packages` | gauge | Packages this registry publishes. |
| `versions` | gauge | Package versions served. |
| `downloads_total` | counter | Dist downloads served, ever. |
| `packages_failing` | gauge | Packages whose last sync recorded an error. |
| `packages_never_synced` | gauge | Packages with a repository that have never synced. |
| `packages_stale` | gauge | Packages not synced in 24 hours. The schedule is hourly, so anything here is behind. |
| `last_sync_age_seconds` | gauge | Since the most recent successful sync of *any* package. |
| `queue_pending_jobs` | gauge | Jobs waiting. Database queue only. |
| `queue_failed_jobs` | gauge | Jobs that exhausted their retries. Database queue only. |
| `queue_oldest_pending_seconds` | gauge | How long the oldest waiting job has waited. Database queue only. |
| `outgoing_webhooks_failing` | gauge | Active [outgoing webhook](outgoing-webhooks.md) endpoints whose last delivery failed. |
| `mirror_documents` | gauge | Upstream metadata documents cached. [Mirroring](mirroring.md) only. |
| `mirror_archives` | gauge | Upstream release archives cached. Mirroring only. |
| `mirror_archive_bytes` | gauge | Disk held by those archives. Mirroring only. |

Packages published by artifact upload have no repository to sync from, so they
are excluded from all three sync-health counts. They are not stale; they are
simply not synced, and counting them would put a permanent floor under every one
of those numbers.

### Series that are deliberately absent

Two groups appear only when they mean something, because a fabricated number is
worse than no number — an alert on `== 0` would be silently satisfied forever.

- **Queue metrics** need the `database` queue driver. On Redis or SQS the depth
  is the broker's to report, and those exporters already exist.
- **Mirror metrics** need at least one enabled upstream, which is no
  installation until an operator adds one.

## What to alert on

```promql
# Nobody is syncing. Catches a dead scheduler, which every per-package
# figure below looks fine about for an hour after it happens.
package_pipeline_last_sync_age_seconds > 7200

# Packages have stopped receiving releases.
package_pipeline_packages_failing > 0

# A dead queue worker. Depth alone cannot tell one from a busy worker —
# a rebuild legitimately queues hundreds — but waiting time can.
package_pipeline_queue_oldest_pending_seconds > 900

# Deliveries swallow their own failures on purpose, so this is the only
# way a broken endpoint reaches an alert.
package_pipeline_outgoing_webhooks_failing > 0

# The mirror cache is the one thing here that maps to a bill, and
# mirror:prune is what bounds it.
package_pipeline_mirror_archive_bytes > 50e9

# The exporter stopped answering at all.
absent(package_pipeline_up)
```

## Cost of a scrape

A scrape every fifteen seconds, forever, is the design constraint. Two things
follow from it.

**Nothing counts the `downloads` table.** It is the fastest-growing table in the
schema and is never pruned. `downloads_total` is summed from the denormalized
`packages.total_downloads` counters, which exist precisely so nothing has to
count it; `downloads:recalculate` is what puts the two back in step if they
drift. Everything else is a count or a `max` over a table measured in hundreds
or thousands.

**The rendered document is cached** for `METRICS_CACHE_SECONDS` (10 by default),
so a second Prometheus replica scraping the same instance costs a cache read.
This bounds *resolution*, not correctness — Prometheus timestamps a sample when
it collects it, so a cached document is not a stale reading. Set it to `0` to
turn the cache off.

Note that the cache is per instance where the cache store is per instance. With
the default `database` store, or with Redis, every app container answers from
one cached document; with `file` or `array`, each container renders its own.

## Why there are no per-package series

A gauge labelled with a package name would make this endpoint's cardinality a
function of how big the registry is. That is the classic way to take a
Prometheus server down with a well-meaning exporter: a registry that grows to a
few thousand packages, times a handful of series each, times a retention window.

It would also publish every private package's name to whoever can scrape, which
is a larger disclosure than any of the numbers above.

So the answers here are counts and ages. When the question is about one package,
the panel answers it, and [the CSV export](download-analytics.md) answers the
historical version of it.
