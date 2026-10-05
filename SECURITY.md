# Security policy

Package Pipeline holds private source code and the credentials that fetch it,
and sits in the install path of every project that depends on it. Reports of
security problems are welcome and are handled ahead of other work.

## Reporting a vulnerability

**Do not open a public issue, discussion or pull request for a security
problem.**

Report it privately through GitHub:
**[Report a vulnerability](https://github.com/AlwaysCuriousCo/package-pipeline/security/advisories/new)**
(the repository's **Security** tab → **Report a vulnerability**). The report is
visible only to you and the maintainers, and the fix can be developed with you
in a private fork attached to it.

If you cannot use GitHub's form, email
[hello@alwayscurious.co](mailto:hello@alwayscurious.co) with "Security" in the
subject line.

A report is most useful when it includes:

- the version or commit you tested, and the database and dist disk drivers if
  they matter;
- which surface is affected — the admin panel, the Composer, npm or PyPI
  endpoints, the management API, an incoming or outgoing webhook, mirroring,
  public pages, SSO, or billing;
- what the attacker starts with: no credential, a token and its abilities, or
  a panel account and its role;
- steps to reproduce, or a proof of concept, against an installation you run;
- what the attacker ends up with.

Test against your own installation. Do not test against a deployment you do
not operate, including any run by the maintainers.

## What to expect

| Step | When |
| --- | --- |
| Acknowledgement that the report arrived | Within 3 business days |
| First assessment: accepted, declined, or questions for you | Within 7 days |
| Fix released for a critical or high severity issue | Target of 30 days from acceptance |
| Public disclosure | With the fixed release, and no later than 90 days after the report unless we agree otherwise |

This is a small project, so these are targets rather than guarantees. If one
is going to be missed you will be told, with the reason.

A fix ships as a new release, with a
[GitHub security advisory](https://github.com/AlwaysCuriousCo/package-pipeline/security/advisories)
that names the affected versions, the fixed version, and anything an operator
has to do beyond upgrading — rotating a secret, for example. A CVE is
requested through GitHub when the issue warrants one. You are credited in the
advisory unless you ask not to be.

There is no bug bounty.

## Supported versions

Security fixes land in the latest release only. Nothing is backported to an
earlier minor or major version.

| Version | Supported |
| --- | --- |
| Latest [release](https://github.com/AlwaysCuriousCo/package-pipeline/releases/latest) | Yes |
| Anything older | No — upgrade to the latest release |

If you report against an older version, say whether the latest release is
affected too.

## Scope

Anything that lets someone do what the registry's access rules say they cannot
is in scope. The reports that matter most:

- **Authentication or authorization bypass** on any endpoint: reading a
  private repository or package without a token, or with a token that was not
  granted it; reaching the panel without a role.
- **Crossing an ability or scope boundary.** The token abilities
  (`repository:read`, `repository:write`, `api:read`, `api:write`,
  `api:delete`) never imply each other, and grants, teams and roles bound what
  a credential sees. A way around any of them is a vulnerability.
- **Server-side request forgery** past the egress rules on mirrored fetches
  or outgoing webhook deliveries.
- **Reading a private repository through a public page**: getting the page
  image route to serve anything but an image, a path outside the repository,
  or a ref other than the page's own.
- **Dependency confusion**: getting an upstream or another repository to
  answer for a name that is published locally or falls under a reserved
  vendor.
- **Tampering with what is served**: replacing or altering a published
  archive or its metadata, or poisoning a mirror or metadata cache entry.
- **Forged webhooks**: a GitHub, GitLab or merchant delivery accepted without
  a valid signature or secret.
- **Secret disclosure**: a source token, upstream credential, webhook secret,
  SSO client secret or plain access token appearing in a response, a log, the
  audit log or an export.
- **Cross-site scripting or HTML injection** through content the registry
  renders but does not author — a README, a package page, package metadata.
- **Code execution, SQL injection or path traversal** anywhere, including
  through an uploaded or mirrored archive.
- **Billing bypass**: obtaining access to a package a plan sells without the
  subscription that grants it.
- **Resource exhaustion from a single cheap request** that gets past the size
  and rate ceilings the app enforces.

### Documented behaviour, not a vulnerability

These are deliberate, documented, and the operator's to configure. A report
that one of them exists will be closed; a report that one of them does more
than its documentation says is in scope.

- The **default repository is created public** until an operator turns that
  off. See [Repositories, and the public default](README.md#repositories-and-the-public-default).
- A **deploy token granted nothing sees everything**. See
  [Authentication](README.md#authentication).
- A **public repository with mirroring on is an open proxy**, bounded per
  request but not by rate. See
  [docs/mirroring.md](docs/mirroring.md#a-public-mirroring-repository-is-an-open-proxy).
- An **upstream's own origin is exempt from the egress rules**, and
  `MIRROR_ALLOW_PRIVATE_DIST_HOSTS`, `MIRROR_PRIVATE_DIST_HOSTS`,
  `WEBHOOK_ALLOW_PRIVATE_ENDPOINTS` and `WEBHOOK_PRIVATE_HOSTS` widen them on
  purpose. See [docs/mirroring.md](docs/mirroring.md#where-a-mirrored-fetch-may-go).
- **`/metrics` is unauthenticated when enabled without `METRICS_TOKEN`.** It
  is off by default. See [docs/metrics.md](docs/metrics.md#authentication).
- The **audit log is not tamper-evident** against someone with database or
  shell access, and its view permission is registry-wide. See
  [The audit log permission is registry-wide](README.md#the-audit-log-permission-is-registry-wide).
- Enabling **Plumb scores** sends package names, private ones included, to a
  third party. It is off by default.

### Out of scope

- Anything that requires access the attacker would already have to be trusted
  with: a `super_admin` account, the server's shell, the database, the `.env`
  file or `APP_KEY`.
- Volumetric denial of service, and rate limiting on endpoints documented as
  unlimited.
- The contents of a mirrored package. This registry relays what an upstream
  published; report the package to its maintainers.
- A vulnerability in a dependency (Laravel, Filament, Livewire and the rest)
  that is not reachable through this application. Report it upstream. If it
  *is* reachable here, it is in scope — say how.
- Scanner output with no demonstrated impact: missing headers, version
  banners, TLS configuration of a particular deployment.
- Problems in one deployment's own configuration or infrastructure. Take
  those to whoever operates it.
- Social engineering, and physical access.

## Safe harbour

Research carried out in good faith under this policy is welcome. If you test
only installations you operate, avoid harming other people's data or service,
and give us a reasonable chance to fix a problem before you publish it, we
will not pursue or support legal action against you for that research.

## For operators

A report is rarely the right tool for a misconfiguration. Before exposing an
installation, read:

- [Repositories, and the public default](README.md#repositories-and-the-public-default)
  — turn **Public** off on the default repository.
- [Authentication](README.md#authentication) — token kinds, abilities and
  expiry.
- [docs/dependency-confusion.md](docs/dependency-confusion.md) — reserve your
  vendor prefixes, and the configuration each consuming project needs.
- [docs/mirroring.md](docs/mirroring.md) — what mirroring exposes, and its
  egress rules.
- [docs/deployment.md](docs/deployment.md) — production drivers, monitoring,
  and backup and restore.

To hear about fixes, watch the repository's releases and security advisories
(**Watch → Custom → Releases** and **Security alerts**).
