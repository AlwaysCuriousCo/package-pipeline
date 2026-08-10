<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/package-pipeline-header-dark.png">
    <source media="(prefers-color-scheme: light)" srcset="art/package-pipeline-header.png">
    <img src="art/package-pipeline-header.png" alt="Package Pipeline — self-hosted Laravel application for Composer package management" width="100%">
  </picture>
</p>

# Package Pipeline

Sharing private PHP packages across projects is a chore: every consuming app needs its own `repositories` entries in `composer.json`, its own GitHub credentials, and Composer crawls the GitHub API repo-by-repo just to resolve versions. Package Pipeline replaces all of that with one self-hosted registry. Point it at your GitHub repositories once, and every project can `composer require` your private packages as if they were on Packagist — one repository URL to configure, no per-repo wiring, and your code never leaves your infrastructure.

**How it works, in one pass:** you register a **package** (a GitHub repository) in the [Filament](https://filamentphp.com) admin panel, a sync job reads its tags and branches and stores each one as a version, and Composer clients fetch `/packages.json`, per-package metadata, and zipball dists straight from the app over the standard [Composer v2 repository API](https://getcomposer.org/doc/05-repositories.md#composer). Each version's zipball is downloaded from GitHub at sync time and stored on a local or S3 disk with its sha1 checksum, so dist downloads are served entirely from the app's own storage. Authentication against GitHub goes through a **source** (a connected GitHub App installation with short-lived, org-owned tokens), a per-package token, or a global fallback token — whichever is available, in that order.

## Requirements

- PHP **8.3+** with Composer
- SQLite (default — nothing to configure), or MySQL/Postgres if you prefer
- A GitHub account with the repositories you want to serve

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
composer require acme/core
```

Composer will resolve versions from the registry and download dists through it; every zipball is served from the archive stored at sync time (and verified against its published `shasum`), so GitHub is never in the download path.

> [!WARNING]
> The Composer endpoints (`/packages.json`, `/p2/...`, `/dist/...`) are currently **unauthenticated** — anyone who can reach the app over the network can list and install your packages. Until repository-level auth lands, run the app somewhere access-controlled: a VPN, a private network, or behind a proxy that enforces auth.

## Configuration reference

Everything lives in `.env`; the interesting knobs beyond a stock Laravel app:

| Variable | Purpose |
| --- | --- |
| `GITHUB_APP_ID` / `GITHUB_APP_PRIVATE_KEY` | The GitHub App that powers sources. The key takes a path to the `.pem` or the key itself with `\n`-escaped newlines. |
| `GITHUB_APP_SLUG` / `GITHUB_APP_API_URL` | Normally read from GitHub automatically; only set to skip that lookup or on GitHub Enterprise. |
| `GITHUB_TOKEN` | Fallback token for packages with neither a connected source nor a token of their own. |
| `DIST_DISK` | Disk where version archives (Composer zipballs) are stored at sync time. Defaults to `FILESYSTEM_DISK`; set to `s3` on any deployment whose containers don't share a local disk. |
| `ARTIFACT_UPLOAD_MAX_MB` | Largest artifact zip `POST /upload/{vendor}/{package}` accepts, in megabytes (default `100`). PHP's `upload_max_filesize` and `post_max_size` have to allow the same size. |

## Development

```bash
composer test        # phpunit via artisan test
composer lint        # code style, report only (Laravel Pint)
composer analyse     # static analysis (PHPStan via Larastan, level 6)
vendor/bin/pint      # apply the fixes `composer lint` reports
```

CI runs all three on every pull request, so a branch that passes them locally is a branch that goes green.

`composer run dev` runs the web server, a queue worker, and `pail` log streaming together — if you run pieces manually instead, remember the queue worker, or panel-triggered syncs will sit in the `jobs` table forever. It multiplexes them with `npx concurrently`, which is the one thing here that wants Node installed; without it, run the three in separate terminals:

```bash
php artisan serve
php artisan queue:listen --tries=1 --timeout=0
php artisan pail --timeout=0
```

## Deploying

The short version for production:

- **Run a queue worker** (`php artisan queue:work --timeout=310`) — package syncs from the admin panel are queued jobs. Keep that timeout between the longest job's own (300 seconds, for a version import streaming a large archive) and the connection's `retry_after` (330): a worker that gives up sooner kills healthy imports, and a `retry_after` that fires first hands a still-running import to a second worker, which downloads and stores the same archive again. Raising `ImportVersion::$timeout` means raising both.
- **Run the scheduler** (`php artisan schedule:work`, or a `* * * * * php artisan schedule:run` cron entry). `routes/console.php` ships the maintenance schedule; `php artisan schedule:list` shows it:

  | Task | When | Why |
  | --- | --- | --- |
  | `packages:sync --queue` | hourly | Releases arrive by webhook, so this is not the normal path. It is what covers packages whose webhook registration failed or was never made, and what makes a partial sync's "the next sync will retry them" true. It is cheap: a ref whose sha hasn't moved is skipped without an API read or a download, so a routine run fans out no import jobs. |
  | `archives:clean` | 03:10 | Re-synced versions leave their previous archive behind by design and nothing else deletes one. |
  | `archives:audit` | 03:20 | The other direction: a version row can outlive its archive (storage loss, a bucket restored from an older snapshot), and nothing in the request path notices — `/p2` keeps advertising the version while `dist` 404s. Syncs deliberately don't check per version, which on S3 was a HEAD request per version per hour; this checks the whole registry with one listing and clears what it can't find, so the next sync downloads it again. |
  | `model:prune` (notifications) | 03:30 | One row per admin per event, kept 30 days once read and 90 days unread. |
  | `queue:prune-batches` | 03:40 | One row per sync, kept 48 hours (72 unfinished). |

  Every task is `onOneServer()`, which needs a shared cache store that supports locks — the default `database`, or `redis`. On a multi-container deployment running `CACHE_STORE=file` or `array`, each container holds its own lock and runs its own copy of every sweep.

- **Seed the permissions** with `php artisan db:seed --force`, after migrating and on any deploy that adds a resource. Shield's policies check permissions that must exist as rows in the database; without them the panel denies everything, super admin included.
- **Create the first admin account** with `php artisan admin:create --email=you@example.com`. A command runner with no terminal attached (Laravel Cloud's, a deploy hook) can't prompt for a password, so the command prints a sealed, single-use link that sets one in the browser instead — no password in the environment, and none in the provider's command log. The link expires after **5 minutes**; re-run the command for a fresh one. It needs no mail configuration.
- **Set `DIST_DISK=s3`** (and the `AWS_*` variables) whenever app containers don't share a filesystem, so every instance sees the same stored archives. On Laravel Cloud, attaching an object storage bucket injects the `AWS_*` values automatically. Downloads are then redirected to short-lived pre-signed URLs rather than streamed through PHP, so the bucket's endpoint has to resolve from wherever `composer install` runs — an internal-only hostname (a MinIO service name, say) breaks clients that the app itself can reach the bucket from.
- **Prune orphaned archives** with `php artisan archives:clean` (`--dry-run` to preview) — re-synced versions write fresh files and leave their old ones behind by design. The scheduler runs this nightly; the command is here for when you want it now.
- **Check for lost archives** with `php artisan archives:audit` (`--dry-run` to preview) — the reverse of the above, for versions whose file is no longer on the dist disk. It clears their `archive_path`, which is all the next sync needs to download them again. Worth running by hand after restoring a bucket. It refuses to act when the disk lists nothing at all, since a misconfigured disk looks exactly like total loss.
- **Register a separate GitHub App per environment** — an app's Setup URL points at exactly one deployment. See [docs/github-app.md](docs/github-app.md).
- A health check endpoint is available at `/up`.

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

- [docs/github-app.md](docs/github-app.md) — registering the GitHub App and connecting sources, including troubleshooting.
- [docs/packistry-feature-analysis.md](docs/packistry-feature-analysis.md) — feature comparison against [Packistry](https://github.com/packistry/packistry) that informs the roadmap.

## License

Open-sourced under the [MIT license](LICENSE).
