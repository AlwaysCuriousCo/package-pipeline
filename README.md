<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/package-pipeline-header-dark.png">
    <source media="(prefers-color-scheme: light)" srcset="art/package-pipeline-header.png">
    <img src="art/package-pipeline-header.png" alt="Package Pipeline — self-hosted Laravel application for Composer package management" width="100%">
  </picture>
</p>

# Package Pipeline

Sharing private PHP packages across projects is a chore: every consuming app needs its own `repositories` entries in `composer.json`, its own GitHub or GitLab credentials, and Composer crawls the provider's API repo-by-repo just to resolve versions. Package Pipeline replaces all of that with one self-hosted registry. Point it at your repositories once, and every project can `composer require` your private packages as if they were on Packagist — one repository URL to configure, no per-repo wiring, and your code never leaves your infrastructure.

**How it works, in one pass:** you register a **package** (a repository on GitHub or GitLab) in the [Filament](https://filamentphp.com) admin panel, a sync job reads its tags and branches and stores each one as a version, and Composer clients fetch `/packages.json`, per-package metadata, and zipball dists straight from the app over the standard [Composer v2 repository API](https://getcomposer.org/doc/05-repositories.md#composer). Each version's zipball is downloaded from the provider at sync time and stored on a local or S3 disk with its sha1 checksum, so dist downloads are served entirely from the app's own storage. Authentication against the provider goes through a **source** (a connected account and its credentials), a per-package token, or — on GitHub only — a global fallback token, whichever is available, in that order.

## Requirements

- PHP **8.3+** with Composer
- SQLite (default — nothing to configure), or MySQL/Postgres if you prefer
- A GitHub or GitLab account with the repositories you want to serve (GitHub Enterprise and self-managed GitLab both work; each source can point at its own API base URL)

No Node.js, and no front-end build step: every page the app serves is Filament's, and Filament ships its own compiled assets.

## Quickstart

Clone and run the one-shot setup script. It installs dependencies, creates `.env`, generates an app key, runs migrations, and seeds the panel's permissions:

```bash
git clone https://github.com/AlwaysCuriousCo/package-pipeline.git
cd package-pipeline
composer run setup
```

Create your admin login. The command prompts for a password, so it never lands in `.env`, in your shell history, or in version control:

```bash
php artisan admin:create --email=you@example.com
```

Re-running it against the same address is safe — it updates that account rather than creating a second one, which also makes it the way to reset a forgotten password. The account is granted the `super_admin` role, which is what gets it into the panel; see [Roles and permissions](#roles-and-permissions).

Start everything (HTTP server, queue worker, and log tail, all in one command):

```bash
composer run dev
```

Log in at <http://localhost:8000> — the root URL redirects to the admin panel at `/admin`.

### Add your first package

The fastest way to sync a private repository is a GitHub token. Create a [fine-grained personal access token](https://github.com/settings/personal-access-tokens) with read-only **Contents** and **Metadata** access to the repositories you care about, and set it in `.env`:

```dotenv
GITHUB_TOKEN=github_pat_...
```

Then in the admin panel go to **Packages → New package**, paste the repository URL, and follow the wizard — it works out the rest from the repo. Trigger a sync from the package's page (syncs run on the queue, which `composer run dev` is already processing), or from the CLI:

```bash
php artisan packages:sync                      # sync everything
php artisan packages:sync acme/core            # one package, by composer name or owner/repo
php artisan packages:sync --source=acme        # only packages under one source
php artisan packages:sync --queue              # dispatch to the queue instead of running inline
```

An inline `packages:sync` or `package:rebuild` stands aside for a package whose sync is already queued or mid-import, rather than writing over it, and says so; run it again once that one lands.

Once a package has synced, its versions, release heatmap, and any sync errors all show on its admin page.

`GITHUB_TOKEN` is deliberately a last resort — it's a person's credential with broad reach. For anything beyond a first spin, connect a **source** instead (next section) and clear the global token.

### Connect a GitHub source (the recommended way)

A source is a GitHub organisation or user connected through a **GitHub App**: tokens expire hourly, access is scoped to exactly the repositories chosen at install time, and the credential belongs to the org — it doesn't break when a person leaves. Registering the app is a one-time, ~5 minute job per deployment; the full walkthrough (including the non-obvious permission gotchas) is in **[docs/github-app.md](docs/github-app.md)**.

Once the app is registered and `GITHUB_APP_ID` / `GITHUB_APP_PRIVATE_KEY` are set, connecting an organisation is one click from **Sources** in the admin panel. Packages are linked to their source automatically by repository owner.

### Connect a GitLab source

GitLab works the same way from the registry's side — sync, versions, dists, webhooks, artifact uploads are all provider-agnostic — but it is connected by hand rather than by an install click, because GitLab has no App to install.

Go to **Sources → New source** and fill in:

| Field | Value |
| --- | --- |
| Provider | **GitLab** |
| Organisation or user | The top-level namespace: the group or username your projects sit under (`acme`). Packages under that namespace are linked to this source automatically. |
| API base URL | Leave empty for gitlab.com. For a self-managed instance this is the **v4 API root**, not the web host: `https://gitlab.example.com/api/v4`. |
| Access token | A GitLab access token (see below). Stored encrypted. |

Then **Test connection** on the source, which lists the projects the token can reach and records the count.

**Which token, and what it needs.** The registry sends it as `PRIVATE-TOKEN`, so anything GitLab calls an access token works: a **group access token** is the closest analogue to a GitHub App installation — it belongs to the group rather than to a person, so it survives someone leaving — with a **project access token** or a personal access token as the alternatives.

| You want | Scope | Role |
| --- | --- | --- |
| Sync versions and serve dists | `read_api` | Reporter or above |
| That, plus webhooks created for you | `api` | Maintainer or above |

`read_api` is enough for everything except creating the webhook: that is a `POST /projects/:id/hooks`, which GitLab does not allow a read-only scope to make and does not allow below Maintainer. A token with only `read_api` still gives you a working package — it just syncs when asked rather than when pushed, and the package's page says so.

**What differs from GitHub.** Worth knowing before you plan around it:

- **There is no account-wide webhook.** The GitHub App has one webhook covering every repository in every installation; GitLab has no equivalent, so every GitLab package carries a hook on its own project. See [docs/webhooks.md](docs/webhooks.md).
- **Deliveries are authenticated by a replayed secret**, not an HMAC signature — GitLab sends the hook's own token back in a header. Same practical effect, different failure modes; again, docs/webhooks.md.
- **The credential is long-lived.** No hourly, automatically-rotated installation tokens. Give the token an expiry in GitLab and put its renewal somewhere you will see it.
- **`GITHUB_TOKEN` is not a fallback for GitLab.** It is a GitHub credential and is deliberately never handed to another provider's API, so a GitLab package needs either a connected source or a token of its own.
- **Nested namespaces are fine.** `group/subgroup/project` is handled; the path is what GitLab calls the project's `path_with_namespace`.

A package can also be added without a source at all, by pasting a GitLab URL into **Packages → New package** and putting a token in the package's own token field. The panel labels that field "GitHub token" — it is used for whatever provider the URL names.

## Roles and permissions

Access to the admin panel is controlled by [Filament Shield](https://filamentphp.com/plugins/bezhansalleh-shield), which layers roles and per-resource permissions over `spatie/laravel-permission`. Manage them under **Shield → Roles** in the panel.

Two rules are worth knowing:

- **A user with no role cannot reach `/admin` at all.** An account existing is never by itself a way in, so a leftover user row is harmless.
- **`super_admin` is an ordinary role that holds every permission**, not a gate that skips the checks. What is ticked in the Roles screen is exactly what the role can do, which keeps access auditable — but it also means the role knows nothing about permissions created after it was granted.

The permissions themselves are seeded from the panel's own resources, pages and widgets, so a fresh database gets them from `php artisan db:seed` (which `composer run setup` already runs).

When you add a resource, page, or widget, generate its permissions and hand them to the super admin:

```bash
php artisan shield:generate --all --panel=admin   # permissions + a policy per model
php artisan admin:create --email=you@example.com          # re-syncs the role to every permission
```

Skip the second command and you will find yourself locked out of the resource you just added. Other roles keep whatever they had — tick the new permissions for each role that should have them.

`shield:generate` writes policy classes into [app/Policies/](app/Policies/), which belong in version control. Deployments only need the database rows, so they run the seeder instead and never generate code.

## Using the registry from a project

In any consuming project:

```bash
composer config repositories.private composer https://packages.example.com
composer config http-basic.packages.example.com token pp_your-token
composer require acme/core
```

Composer will resolve versions from the registry and download dists through it; every zipball is served from the archive stored at sync time (and verified against its published `shasum`), so the provider is never in the download path. `composer audit` works too — the registry answers the advisory endpoint for the packages it serves, from advisories recorded against them in the panel.

Each package's admin page prints both configuration lines with its own name and repository URL already filled in.

### Authentication

Every Composer endpoint is behind an access token: `/packages.json`, `/search.json`, `/list.json`, `/p2/...`, `/dist/...`, `/security-advisories`, and the artifact upload endpoint. The token travels as the HTTP Basic password (the username is ignored) or as a bearer token, and a request without one is answered `401` with a `WWW-Authenticate` challenge — which is also what makes an interactive `composer install` stop and ask for credentials rather than fail obscurely.

Tokens come in two kinds, and the difference is what they can see:

- **Personal tokens**, issued by each panel user from **API tokens** in the user menu. A personal token sees exactly what its owner sees, so revoking a person's panel access revokes their Composer access with it.
- **Deploy tokens**, created under **Deploy tokens** in the sidebar. These are machine principals with no user behind them, for CI. A deploy token sees the repositories and packages it was granted — or everything, if it was granted nothing at all, which is worth knowing before you create one and walk away.

Either kind carries **read** (install packages) or **write** (publish artifact uploads) abilities and can be given an expiry. The plain token exists only at the moment it is issued; the row keeps its sha256 and a short prefix, so a lost token is replaced rather than recovered. Revoking is a soft delete, which keeps the audit trail of what the token was and when it was last used.

Failed authentications are rate limited per address (30 a minute), and the 429 that follows says in as many words that it is a rate limit rather than a rejected token — because the place that message gets read is a CI log.

### Repositories, and the public default

A **repository** here is a Composer repository this registry serves. Every package belongs to exactly one. The default repository answers at the site root; every other one you create is mounted under `/r/{path}`, so a single installation can serve independent registries — a public one and an internal one, say — with independent access rules.

Whether a repository can be read without a token is the `public` flag on it. Repositories you create are private.

> [!IMPORTANT]
> The **default repository is created public**. It stands in for the whole registry as it behaved before repositories and tokens existed, and packages created without choosing a repository land in it — so on a fresh installation, anyone who can reach the app can list and install everything in it. Open **Repositories → Default** and turn **Public** off (or move the packages to a repository of your own) before the app is reachable from anywhere you don't control.

A presented token is always checked, public repository or not. A CI system holding a revoked token hears about it as a `401` rather than continuing to work by accident until someone makes the repository private.

## Configuration reference

Everything lives in `.env`, and `.env.example` carries the same notes in situ. Below are the knobs that belong to this app rather than to a stock Laravel one; the stock ones that matter most in production — `QUEUE_CONNECTION`, `CACHE_STORE`, `FILESYSTEM_DISK`, `AWS_*` — are covered under [Recommended drivers](docs/deployment.md#recommended-drivers).

**Sources and syncing**

| Variable | Purpose |
| --- | --- |
| `GITHUB_APP_ID` / `GITHUB_APP_PRIVATE_KEY` | The GitHub App that powers sources. The key takes a path to the `.pem` or the key itself with `\n`-escaped newlines. |
| `GITHUB_APP_WEBHOOK_SECRET` | The secret set on the app's own webhook — **this is the switch that turns account-wide auto-sync on**. With it, a push to any repository in any installation syncs that package straight away; without it, deliveries to `/incoming/github` are refused `503` and packages fall back to hooks of their own. Must match the value set on the app. See [docs/webhooks.md](docs/webhooks.md). |
| `GITHUB_APP_SLUG` / `GITHUB_APP_API_URL` | Normally read from GitHub automatically; only set to skip that lookup or on GitHub Enterprise. |
| `GITHUB_TOKEN` | Last-resort token, used only for packages with neither a connected source nor a token of their own. Deliberately GitHub-only: it is never handed to another provider's API. Leave it empty once sources are set up. |

GitLab needs no environment variables at all — a GitLab source carries its own token and API base URL on the source record. See [Connect a GitLab source](#connect-a-gitlab-source).

**Storage and limits**

| Variable | Purpose |
| --- | --- |
| `DIST_DISK` | Disk where version archives (Composer zipballs) are stored at sync time. Defaults to `FILESYSTEM_DISK`; set to `s3` on any deployment whose containers don't share a local disk. |
| `ARTIFACT_UPLOAD_MAX_MB` | Largest artifact zip `POST /upload/{vendor}/{package}` accepts, in megabytes (default `100`). PHP's `upload_max_filesize` and `post_max_size` have to allow the same size, or PHP discards the body before the app sees it. |
| `METADATA_CACHE_DAYS` | How long a rendered `/p2` payload is kept (default `7`). Entries are keyed by a fingerprint of the versions behind them, so they supersede themselves rather than needing to be cleared — this only bounds how long the leftovers linger. |
| `METADATA_CACHE_MAX_KB` | Largest rendered payload worth storing (default `4096`). A bigger one is served from the version rows every time, which for a package that fat is the lesser problem. `0` turns the cache off entirely. |

**Queue timing**

| Variable | Purpose |
| --- | --- |
| `DB_QUEUE_RETRY_AFTER` | Seconds before the queue treats a job as abandoned and hands it to another worker (default `330`). Must stay above the longest job timeout (300s, a version import streaming a large archive) or a slow import runs twice at once, downloading and storing the same archive twice. Only raise it. `REDIS_QUEUE_RETRY_AFTER` is the same knob for a Redis queue, with the same default and the same rule. |

**Notifications**

| Variable | Purpose |
| --- | --- |
| `SLACK_BOT_USER_OAUTH_TOKEN` | Slack bot token (`xoxb-…`). Published releases and failed syncs are announced there on top of the panel's own notification bell. |
| `SLACK_BOT_USER_DEFAULT_CHANNEL` | The channel to post in (`#releases`). Both variables are needed; leave either empty to skip Slack entirely. |

## Command reference

The panel is the usual way in, but everything an operator needs can be done without a browser — which is what makes this deployable to a platform whose only interactive surface is a command runner.

**Packages and versions**

| Command | What it does |
| --- | --- |
| `packages:sync [name]` | Sync versions from their sources. Takes a composer name or `owner/repo`; `--source=` narrows to one source, `--queue` dispatches instead of running inline. The scheduler runs `--queue` hourly. |
| `package:rebuild [name]` | Re-import every version, trusting nothing already stored. The recovery path for corrupted archives or metadata drift — reach for it when a sync says everything is current but the output isn't. |
| `package:add <url>` | Create a package from a VCS repository URL and queue its first sync. `--name=`, `--repo=` (which Composer repository to serve it from), `--token=`, `--no-webhook`, `--no-sync`. The scriptable equivalent of the create wizard. |
| `package:delete <name>` | Delete a package, its versions and its stored archives. `--repo=` disambiguates a name served in more than one repository; `--force` skips the confirmation. |

**Archives**

| Command | What it does |
| --- | --- |
| `archives:clean` | Delete stored archives no version references. Re-synced versions leave their previous archive behind by design and nothing else removes one. `--dry-run` lists without deleting. Runs nightly. |
| `archives:audit` | The other direction: find versions whose archive is no longer on the dist disk and clear the reference, so the next sync downloads it again. `--dry-run` reports without touching. Run it by hand after restoring a bucket. |
| `downloads:recalculate` | Rebuild the denormalized `total_downloads` counters from the raw downloads rows. For when the counters and the chart disagree. |

**Accounts and access**

| Command | What it does |
| --- | --- |
| `admin:create --email=` | Create or update an admin account and give it every permission. Prompts for a password when a terminal is attached; `--link` (and any non-interactive runner) prints a sealed, single-use setup link instead. Re-run it after adding a Filament resource so `super_admin` picks up the new permissions. |
| `user:add [email]` | Create an ordinary panel user and print their password setup link. `--name=`, `--role=` (repeatable; roles must already exist). |
| `user:reset-password [email]` | Print a fresh single-use password link for an existing user. The recovery path when someone is locked out and there is no mail configured. |
| `token:add <name>` | Issue a Composer access token. `--user=` for a personal token, `--deploy=` for a deploy token (created if it doesn't exist), `--ability=read\|write` (repeatable, read by default), `--expires-days=`. Prints the plain token once. |
| `token:revoke <prefix>` | Revoke a token by the prefix shown in listings (`pp_ab1cd`). What you run when a credential leaks and you have only the log line naming it. |

`php artisan shield:generate --all --panel=admin` and `php artisan db:seed --force` round these out — see [Roles and permissions](#roles-and-permissions).

## Development

```bash
composer test        # phpunit via artisan test
composer lint        # code style, report only (Laravel Pint)
composer analyse     # static analysis (PHPStan via Larastan, level 6)
vendor/bin/pint      # apply the fixes `composer lint` reports
```

CI runs all three on every pull request, so a branch that passes them locally is a branch that goes green.

`composer run dev` runs the web server, a queue worker, and `pail` log streaming together in one terminal — if you run pieces manually instead, remember the queue worker, or panel-triggered syncs will sit in the `jobs` table forever. `php artisan dev:list` shows what it starts; the equivalent three terminals are:

```bash
php artisan serve
php artisan queue:listen --tries=1 --timeout=0
php artisan pail --timeout=0
```

## Deploying

The short version is below. **[docs/deployment.md](docs/deployment.md)** is the long one: which queue and cache drivers to run and why the defaults stop scaling, what has to be shared once there is more than one container, what `/up` does and doesn't prove, and — the part worth reading before you need it — how to back up the database and the dist disk so that a restore is coherent, and how to repair the drift when it isn't.

- **Run a queue worker** (`php artisan queue:work --timeout=310`) — package syncs from the admin panel are queued jobs. Keep that timeout between the longest job's own (300 seconds, for a version import streaming a large archive) and the connection's `retry_after` (330): a worker that gives up sooner kills healthy imports, and a `retry_after` that fires first hands a still-running import to a second worker, which downloads and stores the same archive again. Raising `ImportVersion::$timeout` means raising both.
- **Run the scheduler** (`php artisan schedule:work`, or a `* * * * * php artisan schedule:run` cron entry). `routes/console.php` ships the maintenance schedule; `php artisan schedule:list` shows it:

  | Task | When | Why |
  | --- | --- | --- |
  | `packages:sync --queue` | hourly | Releases arrive by webhook, so this is not the normal path. It is what covers packages whose webhook registration failed or was never made, and what makes a partial sync's "the next sync will retry them" true. It is cheap: the tag and branch listings are asked conditionally on both providers, so an untouched repository answers `304 Not Modified` (which GitHub does not charge against the rate limit at all), and a ref whose sha hasn't moved is skipped without an API read or a download — a routine run fans out no import jobs. |
  | `archives:clean` | 03:10 | Re-synced versions leave their previous archive behind by design and nothing else deletes one. |
  | `archives:audit` | 03:20 | The other direction: a version row can outlive its archive (storage loss, a bucket restored from an older snapshot), and nothing in the request path notices — `/p2` keeps advertising the version while `dist` 404s. Syncs deliberately don't check per version, which on S3 was a HEAD request per version per hour; this checks the whole registry with one listing and clears what it can't find, so the next sync downloads it again. |
  | `model:prune` (notifications) | 03:30 | One row per admin per event, kept 30 days once read and 90 days unread. |
  | `queue:prune-batches` | 03:40 | One row per sync, kept 48 hours (72 unfinished). |

  Every task is `onOneServer()`, which needs a shared cache store that supports locks — the default `database`, or `redis`. On a multi-container deployment running `CACHE_STORE=file` or `array`, each container holds its own lock and runs its own copy of every sweep. That is a correctness problem rather than a performance one; see [The cache store is not just a cache](docs/deployment.md#the-cache-store-is-not-just-a-cache).

- **Seed the permissions** with `php artisan db:seed --force`, after migrating and on any deploy that adds a resource. Shield's policies check permissions that must exist as rows in the database; without them the panel denies everything, super admin included.
- **Create the first admin account** with `php artisan admin:create --email=you@example.com`. A command runner with no terminal attached (Laravel Cloud's, a deploy hook) can't prompt for a password, so the command prints a sealed, single-use link that sets one in the browser instead — no password in the environment, and none in the provider's command log. The link expires after **5 minutes**; re-run the command for a fresh one. It needs no mail configuration.
- **Set `DIST_DISK=s3`** (and the `AWS_*` variables) whenever app containers don't share a filesystem, so every instance sees the same stored archives. On Laravel Cloud, attaching an object storage bucket injects the `AWS_*` values automatically. Downloads are then redirected to short-lived pre-signed URLs rather than streamed through PHP, so the bucket's endpoint has to resolve from wherever `composer install` runs — an internal-only hostname (a MinIO service name, say) breaks clients that the app itself can reach the bucket from.
- **Register a separate GitHub App per environment** — an app's Setup URL points at exactly one deployment. See [docs/github-app.md](docs/github-app.md).
- **Point health checks at `/up`**, but know what it answers for: the container is up and the framework boots. It runs with no middleware and touches neither the database nor the queue, so it will report a healthy container that has not synced anything in a week. See [Health and monitoring](docs/deployment.md#health-and-monitoring) for what to watch instead.
- **Back the dist disk up before the database**, not after. The two hold one dataset between them, and only one of the two inconsistent orderings is harmless. [Backup and restore](docs/deployment.md#backup-and-restore) explains why, and what repairs the drift.

### Deploying on Laravel Cloud

[Laravel Cloud](https://cloud.laravel.com) runs this app well with almost no configuration. Create the application from your fork of this repository, then:

1. **Attach a database.** App containers are ephemeral, so the SQLite default won't survive a deploy — attach a Cloud database (MySQL or Postgres) from the environment's **Resources** and let Cloud inject the `DB_*` variables.
2. **Attach an object storage bucket** and set `DIST_DISK=s3`. Cloud injects the `AWS_*` variables when the bucket is attached; without it, stored version archives vanish on every deploy and Composer downloads 404 until the next sync rebuilds them.
3. **Add the deploy commands** so migrations and Shield's permission rows are in place before anyone logs in:

   ```bash
   php artisan migrate --force
   php artisan db:seed --force
   ```

4. **Add a queue worker** to the environment (Resources → Queue Worker, default queue) and give it a **310-second timeout**, for the reason above. Package syncs triggered from the admin panel are queued jobs — without a worker they sit in the `jobs` table forever.
5. **Add a scheduler** to the environment (Resources → Scheduler) so the maintenance tasks above actually run. Cloud's scheduler invokes `schedule:run` for you.
6. **Set the GitHub credentials** in the environment's variables: `GITHUB_APP_ID` and `GITHUB_APP_PRIVATE_KEY` for sources (paste the key with `\n`-escaped newlines), or a `GITHUB_TOKEN` to get started quickly.

#### Create your super admin

Once the first deploy is green, open the environment's **Commands** panel and run:

```bash
php artisan admin:create --email=you@example.com
```

Cloud's command runner has no terminal attached, so the command doesn't prompt for a password. Instead it prints a sealed, single-use link to the panel's password-reset screen — open it in your browser and set the password there. The password never passes through Cloud's environment variables or command log, and no mail configuration is needed.

Three things to know about the link:

- **It expires 5 minutes after being printed** (deliberately short, since the URL lands in the command log). If it goes stale, just re-run the command — it updates the existing account and prints a fresh link, which also makes it the recovery path for a forgotten password.
- **It is single-use** — once the password is set, the link is dead.
- **It carries nothing readable.** The address, the reset token and the expiry are encrypted with `APP_KEY` into one opaque path segment, so the URL has no query string at all. That is deliberate: `?email=…&token=…` arriving at a page full of password inputs is the shape of a credential-harvesting kit, and Chrome's Safe Browsing will put a "Dangerous site" interstitial in front of it on a domain that has no reputation yet. Rotating `APP_KEY` invalidates any link already printed.

After adding new Filament resources, re-run both `php artisan shield:generate --all --panel=admin` (locally, committing the generated policies) and `php artisan admin:create --email=you@example.com` (on Cloud) so the `super_admin` role picks up the new permissions — see [Roles and permissions](#roles-and-permissions).

## Further reading

- [docs/deployment.md](docs/deployment.md) — production drivers, scaling, monitoring, and backup and restore.
- [docs/github-app.md](docs/github-app.md) — registering the GitHub App and connecting sources, including troubleshooting.
- [docs/webhooks.md](docs/webhooks.md) — auto-syncing on push: the two GitHub delivery paths, GitLab's per-project hooks, and how to tell whether a package is actually covered.
- [CHANGELOG.md](CHANGELOG.md) — what changed in each release and what it asks of the operator.
- [docs/packistry-feature-analysis.md](docs/packistry-feature-analysis.md) — feature comparison against [Packistry](https://github.com/packistry/packistry) that informs the roadmap.

## License

Open-sourced under the [MIT license](LICENSE).
