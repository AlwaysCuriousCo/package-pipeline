# Upstream mirroring

A project that installs from this registry almost always installs from
packagist.org as well. That means two repositories in every `composer.json`,
two hosts that have to be up for a build to succeed, and — when packagist.org
or GitHub has a bad day — a deployment that fails for reasons nobody in your
organisation can do anything about.

Mirroring closes that. Point a Composer repository here at one or more
**upstreams**, and it starts answering for packages it does not publish: the
metadata is fetched once and cached, the release zip is fetched once, verified
and stored on your dist disk, and every install afterwards is served from
infrastructure you own. One URL resolves a project's whole dependency graph.

It is **off** until you add an upstream. An installation with none behaves
exactly as it always has, down to the last byte of every response.

This document is written about Composer, which is where mirroring grew up,
but an upstream carries an **ecosystem** and the npm and Python surfaces
mirror through the same machinery under the same rules —
[docs/ecosystems.md](ecosystems.md#mirroring-npmjsorg-and-pypiorg) covers
what differs.

> [!IMPORTANT]
> Read [docs/dependency-confusion.md](dependency-confusion.md) first, and
> reserve your vendor prefixes before you turn this on. Mirroring makes this
> registry answer for names it does not publish, which is precisely the
> capability a dependency-confusion attack needs. The rules below are built so
> that it cannot be used that way — but the reservations are what tell the
> registry which names are yours.

## What it is not

- **Not a full mirror of packagist.org.** Nothing is fetched until a Composer
  client asks for it. There is no bulk import, no list to synchronise, and no
  moment where the disk fills up because somebody ran a command.
- **Not an advisory database.** The security-advisories endpoint passes
  mirrored names through to the upstream that served them; nothing is imported
  or stored. See [Security advisories](#security-advisories).
- **Not a replacement for the consumer-side configuration.** A mirroring
  registry still cannot reach into a project's `composer.json`. It makes the
  `exclude` list in [dependency-confusion.md](dependency-confusion.md) easier
  to live with — you can turn packagist.org off in the project entirely — but
  the decision is still the project's.

## Turning it on

Open **Composer repositories → (a repository) → Upstream mirroring** and add
one.

| Field | What it is |
| --- | --- |
| Name | A label for the admin. The URL is the identity. |
| Repository URL | The root of a Composer v2 repository — `https://repo.packagist.org` for packagist.org, or another private registry, a corporate proxy, or another installation of this app. |
| Access token | Sent as the HTTP Basic password (with the username `token`, which every Composer repository ignores). Only needed for an upstream that requires one. |
| Enabled | Turning an upstream off stops it being consulted but keeps what is already cached. |

Add more than one and they are consulted **in order**: the first upstream that
has a package wins, including over a later one that might have a higher version.
That is the same canonical-first-match rule Composer applies to its own
repository list, and it means the order is a decision rather than an accident —
drag to reorder.

Nothing else changes for consuming projects. They already point at this
registry; they can now stop pointing at packagist.org as well:

```json
{
    "repositories": {
        "package-pipeline": {
            "type": "composer",
            "url": "https://registry.example.com"
        },
        "packagist.org": false
    }
}
```

That is [step 3 of the dependency-confusion guide](dependency-confusion.md#3-if-you-install-nothing-from-packagistorg-say-so),
which was previously "the strongest form of the defence and the least often
applicable". With mirroring on, it is applicable to every project.

## The one rule

**A local package always wins, unconditionally.**

Concretely, a name is never served from an upstream when:

1. **This installation publishes it** — in *any* repository, whether or not the
   caller can see it, and whether or not it is the repository being asked. A
   package that exists here is never shadowed by somebody else's package of the
   same name, and a private package a caller may not see answers `404` rather
   than falling through to packagist.org, where an attacker would have put one.
2. **Its vendor is reserved** by any repository here. A reservation says the
   prefix is yours; serving an upstream's `acme/anything` under it would hand
   back exactly the package the reservation exists to refuse. This is the case
   that matters most, because it covers names you have **not published yet** —
   the typo, the package still in review, the one somebody deleted.

Both refusals are decided before any upstream is contacted, and both produce
the ordinary "not served by this repository" `404`. Nothing about the response
tells a caller which rule applied, or that mirroring exists at all.

The consequence worth internalising: **an unreserved vendor is mirrorable.**
If `acme` is not reserved and nothing here publishes `acme/widgets`, then a
consuming project asking this registry for `acme/widgets` gets whatever
packagist.org has under that name. That is correct behaviour for a mirror and
a disaster for a private vendor prefix. Reserve your vendors.

## What consumers see

### `packages.json` advertises everything

Without mirroring, the root document lists the vendor prefixes this repository
serves, so Composer does not ask about anything else. With mirroring on it
advertises `*/*` instead — because the old list would tell Composer never to
ask about `symfony/*`, and the mirror would then serve nothing at all, silently
and for exactly the packages it exists to serve.

The cost is the one that list was added to avoid: Composer now asks this
registry about every package in the graph. Those requests **are** the mirror
lookups, and each is answered from the cache — or from a cached absence —
rather than from a database miss.

### `list.json` and `search.json` stay local

Both keep answering for packages this registry publishes, and neither ever
mentions a mirrored one. They describe what the registry *is*, which stays a
true and stable statement; a mirror's cache holds whichever transitive
dependencies somebody's build happened to pull in, which is an accident of
traffic. A list that grows when an unrelated project installs something and
shrinks when `mirror:prune` runs would be worse than no list.

Resolution does not depend on either of them — the root document's `*/*` is
what tells Composer to ask. To see what is cached, look at the **Cached** column
on the Composer repositories table in the admin panel.

### `dist` URLs point here

A mirrored `/p2` document is served with its `dist.url` rewritten to this
registry, keeping the upstream's own `shasum`. The first request for one of
those URLs fetches the archive, checks it against that `shasum`, and stores it;
every request afterwards is served from the dist disk. Composer's own
verification then confirms a claim that was already checked.

One exception: a version whose dist is not a zip, or carries no `shasum`, keeps
the upstream's URL and is fetched by Composer directly. This registry will not
advertise a dist URL it cannot stand behind — and if the bytes cannot be
verified, it would be vouching for whatever arrived.

## Where a mirrored fetch may go

Mirroring is the only feature that makes this app fetch a URL somebody else
wrote. A `dist.url` is chosen by whoever published the package on the upstream
— on a repository mirroring packagist.org, that is the general public — and an
anonymous `GET /dist/…` is what triggers the fetch. Left unbounded, that is a
server-side request forgery primitive with a stranger holding the pen.

So every destination that is **not the upstream's own origin** is held to
public addresses:

- The host is resolved, and every address it answers with must be public
  unicast. Loopback, RFC 1918, link-local (`169.254.169.254` and every cloud's
  instance metadata service with it), carrier-grade NAT, multicast and the
  reserved ranges are all refused — as are `.internal`, `.local`, `.localhost`,
  `.home.arpa`, `.intranet` and any single-label host, whatever they resolve to.
- **Every redirect hop is judged the same way.** An honest CDN answering
  `302 http://10.0.0.1:9200/` is the same request with one more step in it.
- The addresses that passed are **pinned onto the connection**, so a name that
  answers publicly for the check and privately a millisecond later — DNS
  rebinding — connects to the address that was checked.
- Only `http` and `https`, and at most five redirects.

An **upstream's own origin is exempt**, because an operator who typed
`http://nexus.internal:8081` into the admin panel has already said this app may
reach it. That covers the ordinary self-hosted case end to end: a Nexus or
Artifactory that serves its own archives needs no configuration here at all.

What is left is the upstream that signs URLs for a *separate* internal object
store. Name it:

```dotenv
MIRROR_PRIVATE_DIST_HOSTS=objects.internal,minio.internal
```

`MIRROR_ALLOW_PRIVATE_DIST_HOSTS=true` turns the address rules off altogether.
Prefer the list: it is the same escape hatch scoped to the hosts you meant.

A refused destination is logged and answered `404`, exactly like an archive
that failed its checksum — from the consumer's side there is no archive this
registry is prepared to stand behind, and why is the operator's business.

## Security advisories

`composer audit` — and the audit that runs inside every `composer update` since
Composer 2.9 — posts to this registry's `/security-advisories`. For a mirrored
name, the request is passed through to the upstream it came from, and the
answer merged in.

This is deliberate. Without it, mirroring would quietly switch auditing off for
the mirrored majority of a project's dependency graph: "this repository
reported nothing" and "nobody checked" are indistinguishable at the consumer's
end, so the failure would be silent and permanent. Pointing a project at this
registry must not make it less safe than pointing it at packagist.org did.

Names this registry publishes are answered from its own advisory rows and are
never asked about upstream — an upstream has no business making claims about
your packages. Answers are cached for a few minutes (`MIRROR_ADVISORY_TTL_MINUTES`),
because an audit runs on every build and the feed behind the answer is itself
hours old. A failed advisory lookup is never fatal: a broken audit must not
break an install.

The upstream is also asked on a short budget. Composer allows its request to
this app ten seconds, so a mirroring registry that spent its ordinary API
timeout on the upstream would blow the caller's budget and fail the audit
rather than merely answering it late. An upstream that has not answered inside
that gets left out of this audit, which is indistinguishable from an upstream
that had nothing to report.

## Freshness

| | Default | What it bounds |
| --- | --- | --- |
| `MIRROR_METADATA_TTL_MINUTES` | 60 | How long a cached document is served without asking the upstream anything. |
| `MIRROR_MISSING_TTL_MINUTES` | 10 | The same for a name the upstream does not have. |

Past the TTL the next request **revalidates** rather than re-downloads: the
upstream's own `ETag` and `Last-Modified` are replayed to it, and an unchanged
package answers `304` with no body. So the metadata TTL trades staleness
against a round trip, not against bandwidth — an hour is well inside how long a
new release takes to propagate through Composer's own caches anyway.

The missing TTL is much shorter because a package published a minute ago is
exactly the one somebody is waiting on. It cannot be zero: a typo in a
`composer.json` would then cost an upstream request on every resolve, forever.

None of this is per request. A `composer update` of a few hundred dependencies
against a warm cache makes **no** outbound requests at all.

Nor is it per *requester*. When something is cold, the first request fetches it
and the rest wait for that one — up to `MIRROR_LOCK_WAIT_SECONDS` (default 10)
— rather than each making the same call. Fifty CI builds starting together on
the same commit make one upstream fetch and one archive download between them.
A wait that runs out is not an error: a metadata lookup falls back to whatever
is cached, and an archive is fetched again rather than failing a build.

Expiry is spread, too. A cold update fills the cache with hundreds of documents
inside a second, and a plain TTL would expire all of them in the same second an
hour later — so each document's window is shortened by up to a tenth, by an
amount cut from its own name. Deterministic, so a document does not change its
mind about being fresh between two lookups in one request.

Clients get validators of their own, cut from a digest of the upstream bytes
rather than from when they were fetched — so revalidating a package the
upstream has not touched does not tell every client its copy is stale.

## When the upstream is down

The promise: **a package this registry publishes stays installable no matter
what an upstream is doing.** The local path never consults an upstream, so an
outage is not survived so much as never met.

For mirrored packages:

- **Unreachable, or a 5xx** — whatever is cached is served, however stale. An
  hour-old copy of `symfony/console` resolves a build; an error does not. An
  upstream that has just failed is then left alone for
  `MIRROR_FAILURE_BACKOFF_MINUTES` (default 5), during which cached documents
  still answer and nothing touches the network. Without that, an outage would
  cost every lookup a connect timeout — one `composer update` would spend
  minutes discovering the same thing once per dependency, holding a PHP worker
  open for each.
- **404 or 410** — remembered as "no such package" for the missing TTL. Every
  *other* unsuccessful status is an outage, never a missing package: recording
  a 503 as an absence would hide the package for the whole TTL and hide the
  outage entirely.
- **A 200 that is not a Composer document** — a proxy's login page, an HTML
  error, a v1 repository — is treated as the upstream being broken and cached
  neither way.
- **An archive whose sha1 does not match** what the upstream published is
  refused, not stored, and answered `404`. Composer would have caught it too,
  but only after this registry had already stored the bytes and served them
  under a hash it was vouching for.
- **Anything over the size ceilings** (`MIRROR_MAX_ARCHIVE_MB`,
  `MIRROR_MAX_METADATA_KB`) is refused. The upload ceiling bounds what one of
  your own tokens may spend; these bound what a *stranger's* published package
  can, since any name a consuming project requires can reach them.

  Both are enforced **as the bytes arrive**: the transfer is abandoned at the
  ceiling rather than measured once it is over. That distinction is the
  difference between a limit and a report — an archive lands in the system
  temporary directory, which is usually the root volume rather than the dist
  disk, and until it is refused the only thing bounding it is the four-minute
  archive timeout. A `Content-Length` past the ceiling refuses the transfer
  before it starts; a dishonest one changes nothing, because the ceiling is
  applied to the writes either way.

## Disk cost, and pruning

This is the part to size before turning it on. Mirroring is on-demand caching
with no ceiling of its own: every transitive dependency of every project that
resolves through a mirroring repository is fetched once and kept.

A rough shape for a typical PHP application:

- **Metadata** — tens of KB per package document, in the database
  (`mirrored_packages`). A few hundred dependencies is single-digit MB.
- **Archives** — hundreds of KB to a few MB per release, on the dist disk
  (`mirrored_archives`). This is where the disk actually goes. A hundred
  packages at a handful of versions each is comfortably under a gigabyte;
  a registry serving many projects with divergent lock files is several.

`mirror:prune` is the only thing that ever deletes any of it, and it runs
nightly at 03:15:

```bash
php artisan mirror:prune --dry-run   # report what would go, and how much disk
php artisan mirror:prune
```

Retention is measured on **last use**, not on age (`MIRROR_RETENTION_DAYS`,
default 30). A package every build in the company installs is never evicted,
however long ago it was first cached; one pulled in by a single spike leaves
after the window. Nothing is lost either way — a pruned entry costs one
upstream fetch the next time it is wanted. Lower the number when disk is tight;
it is the knob that decides what the mirror costs.

The sweep also deletes mirrored files on the disk that no row claims — what a
deleted upstream's cascade leaves behind, and what a crash between storing an
archive and recording it leaves.

### It does not collide with `archives:clean`

Mirrored archives live under `mirror/` on the dist disk; the archives of
packages this registry publishes live under `packages/`. `archives:clean` and
`archives:audit` both list only `packages/` and both reconcile it against
`package_versions`, a table that knows nothing about the mirror. A shared prefix
would make every cached archive an orphan to one command and evidence of
archive loss to the other, so the separation is load-bearing rather than tidy.

## Access control

A mirrored package is served through an authenticated, repository-scoped mount
like everything else, and the rule is: **a principal that may read the
repository may read what the repository mirrors.** That is the only coherent
reading — a grant names a package this registry publishes, and a mirrored
package is not one.

In practice: a public repository's mirrored packages are readable without a
token; a private one's need a token that reaches that repository. A token
scoped to a *different* repository is refused, even though the authentication
middleware would let it through — per-package visibility, which normally
narrows such a token to nothing, has no rows to narrow here.

The part to be deliberate about: **a grant on one package in a repository
carries the whole of that repository's mirror.** A deploy token granted only
`acme/widgets` can pull every mirrored package in the repository serving it.
That is not an oversight — it is what makes the feature work at all, since the
reason to grant that token anything is so its build can install `acme/widgets`
*and its transitive dependencies*, which are exactly the mirrored packages. A
narrower rule would mean a CI token that can fetch your package and none of
what it needs.

> [!WARNING]
> Both of the above mean the same thing: **whatever an upstream serves is
> served on to everyone who can read anything in the repository the upstream
> belongs to** — and it is fetched using the token you gave that upstream. If
> an upstream is somebody's private registry, put it behind a private
> repository whose readers you would have given that registry's credentials to
> anyway. Attach packagist.org, which is public, wherever you like.

### A public mirroring repository is an open proxy

Composer read endpoints are not rate-limited — they never touched anything but
local rows, so there was nothing to limit. On a **public** repository with
mirroring on, that changes: an anonymous request can now make this app fetch
from an upstream, and a `/dist/…` request can make it download and keep up to
`MIRROR_MAX_ARCHIVE_MB` for `MIRROR_RETENTION_DAYS`. Somebody who wants to can
walk packagist.org and fill the disk your private packages are served from.

That ceiling is what one request costs, not what one request can be made to
spend: the transfer stops there rather than being measured afterwards, and
[where a fetch may go](#where-a-mirrored-fetch-may-go) bounds the addresses it
can be pointed at. What is left to bound is how *many* such requests arrive.

There is no ceiling for this in the app, because a per-minute limit tight
enough to matter is also tight enough to break a legitimate cold
`composer install` of a few hundred dependencies. Choose one of:

- **Keep mirroring on private repositories.** The common case, and the whole
  problem goes away — a caller needs a token first.
- **Put the public mount behind a rate limit at the proxy** (nginx, a CDN, a
  load balancer), where the limit can be tuned to your traffic.
- **Watch the disk.** Lower `MIRROR_RETENTION_DAYS` and alert on the dist disk;
  `mirror:prune --dry-run` reports what is currently held.

Downloads of mirrored archives are not counted. `total_downloads` is a
statement about packages this registry publishes — it drives the dashboard
charts and the package table — and folding somebody else's release into it
would answer a question nobody asked.

## Configuration reference

All optional; the defaults are the recommended values.

| Variable | Default | Purpose |
| --- | --- | --- |
| `MIRROR_METADATA_TTL_MINUTES` | `60` | How long a cached upstream document is served before the next request revalidates it. |
| `MIRROR_MISSING_TTL_MINUTES` | `10` | The same for a name the upstream does not have. |
| `MIRROR_ADVISORY_TTL_MINUTES` | `10` | How long an upstream's advisory answer is reused. |
| `MIRROR_FAILURE_BACKOFF_MINUTES` | `5` | How long an upstream that has just failed is left alone, serving only what is cached. |
| `MIRROR_LOCK_WAIT_SECONDS` | `10` | How long a request waits for another process already fetching the same package or archive. |
| `MIRROR_RETENTION_DAYS` | `30` | How long since last use a cached document or archive survives `mirror:prune`. The knob that bounds disk. |
| `MIRROR_MAX_ARCHIVE_MB` | `256` | Largest upstream archive that will be cached. |
| `MIRROR_MAX_METADATA_KB` | `8192` | Largest upstream metadata document that will be cached. |
| `MIRROR_PRIVATE_DIST_HOSTS` | *(empty)* | Hosts the [egress rules](#where-a-mirrored-fetch-may-go) do not apply to, comma separated. For the upstream that signs URLs for an internal object store. |
| `MIRROR_ALLOW_PRIVATE_DIST_HOSTS` | `false` | Turns those rules off for every host. Prefer the list above. |

## Further reading

- [docs/dependency-confusion.md](dependency-confusion.md) — reserving vendors
  here, and the Composer configuration each consuming project needs. Read
  before enabling mirroring.
- [Repository priorities](https://getcomposer.org/doc/articles/repository-priorities.md)
  — canonical repositories, `only`, `exclude`, and how Composer's own lookup
  order works.
- [docs/deployment.md](deployment.md) — the dist disk, and backing it up.
