<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/package-pipeline-header-dark.png">
    <source media="(prefers-color-scheme: light)" srcset="art/package-pipeline-header.png">
    <img src="art/package-pipeline-header.png" alt="Package Pipeline — self-hosted Laravel application for Composer package management" width="100%">
  </picture>
</p>

# Package Pipeline

Package Pipeline is a self-hosted private Composer registry with a [Filament](https://filamentphp.com) admin panel. Point it at your GitHub repositories and it syncs their tags and branches into versioned package metadata, then serves everything over the standard [Composer v2 repository API](https://getcomposer.org/doc/05-repositories.md#composer) — so any project can `composer require` your private packages without them ever touching Packagist.

**How it works, in one pass:** you register a **package** (a GitHub repository) in the admin panel, a sync job reads its tags and branches and stores each one as a version, and Composer clients fetch `/packages.json`, per-package metadata, and zipball dists straight from the app. Zipballs are proxied from GitHub once and cached on a local or S3 disk. Authentication against GitHub goes through a **source** (a connected GitHub App installation with short-lived, org-owned tokens), a per-package token, or a global fallback token — whichever is available, in that order.

## Requirements

- PHP **8.3+** with Composer
- Node.js 20+ with npm (builds the admin panel assets)
- SQLite (default — nothing to configure), or MySQL/Postgres if you prefer
- A GitHub account with the repositories you want to serve

## Quickstart

Clone and run the one-shot setup script. It installs PHP and JS dependencies, creates `.env`, generates an app key, runs migrations, and builds assets:

```bash
git clone https://github.com/AlwaysCuriousCo/package-pipeline.git
cd package-pipeline
composer run setup
```

Create your admin login. The seeder reads the credentials from `.env` so nothing personal lands in version control — open `.env`, fill in these two (name is optional), then seed:

```dotenv
SUPER_ADMIN_EMAIL=you@example.com
SUPER_ADMIN_PASSWORD=choose-something-strong
```

```bash
php artisan db:seed
```

Start everything (HTTP server, queue worker, log tail, and Vite, all in one command):

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

Once a package has synced, its versions, release heatmap, and any sync errors all show on its admin page.

`GITHUB_TOKEN` is deliberately a last resort — it's a person's credential with broad reach. For anything beyond a first spin, connect a **source** instead (next section) and clear the global token.

### Connect a GitHub source (the recommended way)

A source is a GitHub organisation or user connected through a **GitHub App**: tokens expire hourly, access is scoped to exactly the repositories chosen at install time, and the credential belongs to the org — it doesn't break when a person leaves. Registering the app is a one-time, ~5 minute job per deployment; the full walkthrough (including the non-obvious permission gotchas) is in **[docs/github-app.md](docs/github-app.md)**.

Once the app is registered and `GITHUB_APP_ID` / `GITHUB_APP_PRIVATE_KEY` are set, connecting an organisation is one click from **Sources** in the admin panel. Packages are linked to their source automatically by repository owner.

## Using the registry from a project

In any consuming project:

```bash
composer config repositories.private composer https://packages.example.com
composer require acme/core
```

Composer will resolve versions from the registry and download dists through it; each zipball is fetched from GitHub once and cached.

> [!WARNING]
> The Composer endpoints (`/packages.json`, `/p2/...`, `/dist/...`) are currently **unauthenticated** — anyone who can reach the app over the network can list and install your packages. Until repository-level auth lands, run the app somewhere access-controlled: a VPN, a private network, or behind a proxy that enforces auth.

## Configuration reference

Everything lives in `.env`; the interesting knobs beyond a stock Laravel app:

| Variable | Purpose |
| --- | --- |
| `SUPER_ADMIN_EMAIL` / `SUPER_ADMIN_PASSWORD` / `SUPER_ADMIN_NAME` | Credentials the `UserSeeder` uses to create (or reset) the admin account. |
| `GITHUB_APP_ID` / `GITHUB_APP_PRIVATE_KEY` | The GitHub App that powers sources. The key takes a path to the `.pem` or the key itself with `\n`-escaped newlines. |
| `GITHUB_APP_SLUG` / `GITHUB_APP_API_URL` | Normally read from GitHub automatically; only set to skip that lookup or on GitHub Enterprise. |
| `GITHUB_TOKEN` | Fallback token for packages with neither a connected source nor a token of their own. |
| `DIST_DISK` | Disk for cached Composer zipballs. Defaults to `FILESYSTEM_DISK`; set to `s3` on any deployment whose containers don't share a local disk. |

## Development

```bash
composer test        # phpunit via artisan test
vendor/bin/pint      # code style (Laravel Pint)
```

`composer run dev` runs the web server, a queue worker, `pail` log streaming, and Vite together — if you run pieces manually instead, remember the queue worker, or panel-triggered syncs will sit in the `jobs` table forever.

## Deploying

The short version for production:

- **Run a queue worker** (`php artisan queue:work`) — package syncs from the admin panel are queued jobs.
- **Schedule syncs if you want them automatic.** Nothing is scheduled out of the box; add something like this to `routes/console.php` and run the scheduler (`php artisan schedule:work`, or cron):

  ```php
  use Illuminate\Support\Facades\Schedule;

  Schedule::command('packages:sync --queue')->hourly();
  ```

- **Set `DIST_DISK=s3`** (and the `AWS_*` variables) whenever app containers don't share a filesystem, so every instance sees the same zipball cache. On Laravel Cloud, attaching an object storage bucket injects the `AWS_*` values automatically.
- **Register a separate GitHub App per environment** — an app's Setup URL points at exactly one deployment. See [docs/github-app.md](docs/github-app.md).
- A health check endpoint is available at `/up`.

## Further reading

- [docs/github-app.md](docs/github-app.md) — registering the GitHub App and connecting sources, including troubleshooting.
- [docs/packistry-feature-analysis.md](docs/packistry-feature-analysis.md) — feature comparison against [Packistry](https://github.com/packistry/packistry) that informs the roadmap.

## License

Open-sourced under the [MIT license](LICENSE).
