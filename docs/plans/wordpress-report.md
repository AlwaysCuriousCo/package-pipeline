# WordPress support: build report

Tracking issue: [#46](https://github.com/AlwaysCuriousCo/package-pipeline/issues/46).
How to use it: [docs/wordpress.md](../wordpress.md).

## What was built

- **A package kind, not an ecosystem.** `packages.wordpress_kind`
  (`wordpress-plugin` / `wordpress-theme`) and `packages.wordpress_slug`
  (unique across the registry). A WordPress package is still a Composer
  package: it syncs from git tags and `/p2` and `/dist` serve it exactly as
  before. Both columns are audited.
- **A second zip per version, rooted at the slug.** Built at import by copying
  the Composer dist (already stripped of the provider's `owner-repo-sha/` and
  cut down to a monorepo subdirectory) and re-rooting it with the existing
  `ArchiveSubtree`. Path, sha1 and size are stored in
  `package_versions.wordpress`, a new column, so the composer.json in
  `metadata` that `/p2` serves is untouched. The Composer archive is never
  rewritten: marking a synced package, or changing its slug, backfills only the
  WordPress zips. `archives:clean` counts them as referenced.
- **Header parsing.** A port of `get_file_data()`: the main plugin file
  (`{slug}.php` first) or `style.css`, reading `Version`, `Requires at least`,
  `Tested up to`, `Requires PHP`, author, URIs and description, plus
  `CHANGELOG.md`. A header version that disagrees with its tag is a warning in
  `sync_error`; the release is still served.
- **Endpoints** (`routes/wordpress.php`, `WordPressController`), at the root
  and under `/r/{path}`: plugin and theme update-check (form-encoded JSON as
  core sends it, or a JSON body), `plugin_information` / `theme_information`,
  and `/wp/dist/{slug}/{version}.zip`. Unknown slugs are omitted from update
  checks. `new_version` is the header version, so a site is never offered the
  same update twice. Version ceilings and download analytics apply as for
  Composer.
- **Auth** (`AuthenticateWordPress`): wraps `AuthenticateComposer` and only
  rewrites its refusals to `401 {}` (a 403 for a missing ability included);
  `429` keeps `Retry-After`. No new credential types.
- **Panel:** kind and slug on the wizard (pre-filled from a composer.json
  `type` of `wordpress-*`) and the edit form; slug and dist URL on the package
  page; a **Header version** column on the versions table, red on mismatch.
- **Management API:** each version carries `wordpress.{version, shasum,
  size}`, so a provisioning job can verify the zip it downloads.
- **Tests:** `tests/Feature/WordPressRegistryTest.php`, 16 tests covering the
  slug-rooted repack (root and monorepo), header reading, the mismatch warning,
  backfill without moving the Composer archive, update-check
  (newer / `no_update` / unknown omitted / JSON body / deploy-token scoping),
  the empty-401 refusals, `plugin_information`, a theme round trip including
  the download and its count, the Composer dist still serving,
  `archives:clean`, the panel, and the API.

## Found along the way

- **The WordPress dist cannot redirect to a signed storage URL.** WordPress's
  HTTP client (Requests) re-sends the original headers on a redirect, and core
  does not strip `Authorization` on a cross-host hop (checked against
  WordPress 7.1.3's `class-wp-http.php`). The site's bearer token would reach
  S3 beside the URL's own signature, which S3 refuses. The WordPress dist
  therefore always streams through the app. That ties up a worker per download,
  which is fine at update and provisioning volume, and is marked with a
  `ponytail:` comment.

## Left out

- **Plugins without a composer.json.** Every published tag needs a
  composer.json `name`, as for every synced package. Supporting header-only
  repositories means a WordPress-native import path that does not depend on
  composer.json.
- **Changelog from git tag messages.** `RepositoryClient` has no call for
  annotated tag messages, and adding one to both providers was out of
  proportion; only `CHANGELOG.md` is read.
- **`archives:audit` does not check WordPress zips.** A lost one answers 404
  until `package:rebuild`.
- **Icons, banners, screenshots, ratings, translations.** `translations` is
  always empty.
- **The mu-plugin.** Aether owns it; docs/wordpress.md has the reference
  filter code, which has not been run against a live WordPress install here.
- **Single-file plugins** (`hello.php` with no directory) cannot be served,
  because the slug is the directory.

## Verification

- Full suite: `php artisan test --parallel` passes.
- `vendor/bin/pint --test` passes.
- PHPStan passes on every changed file. A whole-project run timed out a
  parallel worker at 600 seconds before reaching a result, which is an
  environment limit rather than a finding.
