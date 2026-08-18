<p align="center">
  <img src="art/package-pipeline-header.png" alt="Package Pipeline — a self-hosted Composer registry with a Filament admin panel" width="100%">
</p>

# Package Pipeline

**A self-hosted Composer registry for your private PHP packages, with a [Filament](https://filamentphp.com) admin panel.**

Sharing private PHP packages across projects is a chore: every consuming app
needs its own `repositories` entries, its own GitHub or GitLab credentials, and
Composer crawls the provider's API repository by repository just to resolve
versions. Package Pipeline replaces all of that with one registry you run
yourself. Point it at your repositories once, and every project can
`composer require` your packages as if they were on Packagist — one repository
URL to configure, no per-repo wiring, and your code never leaves your
infrastructure.

## What you get

- **The full [Composer v2 repository API](https://getcomposer.org/doc/05-repositories.md#composer)** — `packages.json`, `search.json`, `list.json`, per-package metadata and `dist` zipballs. Consuming projects need one `repositories` entry and nothing else.
- **Syncing from GitHub and GitLab.** Tags and branches become versions; each version's zipball is stored on your own disk or S3 with its checksum, so installs are served entirely from your storage.
- **Auto-sync on push**, through a GitHub App webhook covering every repository at once, or per-repository hooks on either provider.
- **Several repositories in one installation** — a public one and an internal one, say, with independent access rules and independent tokens.
- **Access tokens with abilities**, so the token in every developer's `auth.json` can install packages without also being able to delete one.
- **Monorepo support**: several packages from one repository, each published from its own subdirectory with a re-rooted dist archive.
- **Upstream mirroring** of packagist.org or another registry, so one URL resolves a project's whole dependency graph and a build stops depending on somebody else's uptime.
- **Reserved vendor prefixes**, the server half of a dependency-confusion defence.
- **Artifact uploads** for packages built in CI rather than synced from a repository.
- **Download analytics, a license report and a CycloneDX SBOM export.**
- **An audit log, teams, SSO, outgoing webhooks and a Prometheus endpoint** for the operational side.

## Requirements

PHP 8.3+, a database (SQLite, MySQL or PostgreSQL), a queue worker and a
scheduler. Node 22.19+ is needed at build time for the panel's stylesheet.

## Getting started

```bash
git clone https://github.com/AlwaysCuriousCo/package-pipeline.git
cd package-pipeline
composer run setup
php artisan admin:create --email=you@example.com
composer run dev
```

Then open <http://localhost:8000>, log in, and add your first package. The full
walkthrough — connecting a GitHub App source, configuring consuming projects,
deployment, and the configuration reference — is in the
[README](README.md).

## Using it from a project

```bash
composer config repositories.acme composer https://packages.example.com
composer require acme/your-package
```

For a private registry, the token goes in the consuming project's `auth.json`:

```bash
composer config --auth http-basic.packages.example.com token <your-token>
```

## Documentation

| Guide | What it covers |
| --- | --- |
| [Public pages](docs/public-pages.md) | Publishing a readable page like this one for a package or a repository |
| [Webhooks](docs/webhooks.md) | Auto-syncing on push, and telling whether a package is actually covered |
| [GitHub App](docs/github-app.md) | Registering the app and connecting sources |
| [Monorepos](docs/monorepos.md) | Publishing several packages from one repository |
| [Mirroring](docs/mirroring.md) | Serving packagist.org through your own registry |
| [Dependency confusion](docs/dependency-confusion.md) | Reserving vendors, and the Composer config each project needs |
| [Management API](docs/api.md) | The `/api/v1` surface, end to end |
| [Deployment](docs/deployment.md) | Production drivers, scaling, backup and restore |

## License

Package Pipeline is open-sourced software licensed under the
[MIT license](LICENSE).
