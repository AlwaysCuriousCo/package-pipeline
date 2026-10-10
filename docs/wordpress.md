# WordPress plugins and themes

A Composer package that is also a WordPress plugin or theme can be served to
WordPress sites from the same registry: as a zip WordPress can install, and
through the slice of the api.wordpress.org update API that tells a site a new
version exists. A site points at it with a small mu-plugin, using the same
access tokens Composer uses. No wp-admin credentials, no second account.

Composer keeps working exactly as before. The same package, the same versions,
the same dist archives and the same checksums are served to Composer clients;
WordPress gets a second zip per version beside them.

## Registering a plugin or theme

Add the package as usual (**Packages → New package**, paste the repository
URL), and on the wizard's second step set:

| Field | Value |
| --- | --- |
| WordPress | **WordPress plugin** or **WordPress theme** |
| WordPress slug | The directory WordPress installs it into: `acme-forms` for `wp-content/plugins/acme-forms`, `acme-studio` for `wp-content/themes/acme-studio` |

When the repository's `composer.json` already declares `"type":
"wordpress-plugin"` or `"wordpress-theme"`, the wizard fills both in, taking
the package half of the Composer name as the slug. Correct the slug if the
plugin's directory is called something else. The same two fields are on the
edit form, so an existing package can be marked later.

A slug is lowercase letters, digits, hyphens and underscores, and is unique
across the whole registry (not per repository): a site has exactly one
`wp-content/plugins/acme-forms`, and the update API is asked by slug alone.

A WordPress package is still a Composer package, so **every tag it publishes
needs a `composer.json` with a `name`**, as every synced package does. A
minimal one is enough:

```json
{
    "name": "acme/acme-forms",
    "type": "wordpress-plugin"
}
```

A monorepo works as it does for Composer: set **Subdirectory** to the
directory holding the plugin, and its zip carries that directory alone. See
[monorepos.md](monorepos.md).

## What the sync reads

Versions still come from git tags (`v1.2.0` publishes `1.2.0`), exactly as for
any Composer package. For each one, the sync also builds the WordPress zip and
reads the header WordPress itself would read, from the bytes it is about to
serve:

- **Plugins:** the top-level PHP file carrying a `Plugin Name:` header.
  `{slug}.php` is checked first, then the others in name order, which is how
  WordPress finds a plugin's main file.
- **Themes:** `style.css`, carrying `Theme Name:`.

From that file it records `Version`, `Requires at least`, `Tested up to`,
`Requires PHP`, `Author`, `Author URI`, `Plugin URI` or `Theme URI`, and
`Description`. Only the first 8 KB is read, as WordPress does. If the package
has a `CHANGELOG.md` at its root, that is stored too (up to 64 KB) and shown as
the changelog tab of the plugin details modal.

**A header `Version` that disagrees with its tag is a warning, not a
failure.** The release is still stored and served; the package's sync status
says which tags disagree (`1.1.0 (header: 1.0.9)`), and the versions table's
**Header version** column shows them in red. Fix it in the next release: a site
compares the header version, so the update API advertises the header's version
rather than the tag's (see below), and a release whose header was never bumped
is one a site already running the previous header will not be offered.

Marking an already-synced package as a plugin or theme, or changing its slug,
builds the missing zips on the next sync without touching the Composer
archives. Composer lockfiles pin each archive's sha1, so those are never
rebuilt behind a consumer's back.

## The zip and its checksum

Each version's WordPress zip has exactly one top-level directory, named after
the slug:

```
acme-forms/
acme-forms/acme-forms.php
acme-forms/composer.json
acme-forms/includes/...
```

It is served at:

```
GET https://packages.example.com/wp/dist/{slug}/{version}.zip
GET https://packages.example.com/r/{repository}/wp/dist/{slug}/{version}.zip
```

with the version as tagged (`1.1.0`). The package's page in the panel shows the
URL for its latest release. The sync records the zip's sha1 and its size in
bytes; the update API does not repeat them (WordPress has no field for them),
so a provisioning job that wants to verify a download reads them from the
version (see [Provisioning a site](#provisioning-a-site)).

Downloads are counted against the version exactly as a Composer download is,
and show in the same analytics. The zip is always streamed by the app rather
than redirected to the storage service: WordPress forwards the request's
`Authorization` header to wherever a redirect points, and a signed S3 URL
refuses a request carrying a second credential.

## Authentication

Every WordPress endpoint takes the same access tokens as Composer, with the
same abilities: a token holding **repository:read**, sent either as a bearer
token or as the HTTP Basic password with any username. Personal access tokens
and deploy tokens both work, and a deploy token sees exactly the packages and
repositories it was granted. A public repository answers without a token, as
it does for Composer.

A refusal (no token, a revoked or unknown one, or one without
`repository:read`) is always `401` with the body `{}`. Nothing a site's update
check might try to merge as a list of updates comes back with it. Too many
failed attempts from one address is `429` with `Retry-After`, also with `{}`.

Use a deploy token per site or per fleet, scoped to the repository or packages
the site installs, rather than a person's token.

## The update API

These follow api.wordpress.org's own paths under `/wp`, at the registry root
and under `/r/{repository}` for a named repository. Use them without a trailing
slash: a web server that redirects `.../1.1/` to `.../1.1` turns the POST into
a GET.

### Update checks

```
POST /wp/plugins/update-check/1.1
POST /wp/themes/update-check/1.1
```

The request is what `wp_update_plugins()` and `wp_update_themes()` send to
wordpress.org: a form-encoded `plugins` (or `themes`) field holding JSON.

```
plugins={"plugins":{"acme-forms/acme-forms.php":{"Version":"1.0.0"}},"active":["acme-forms/acme-forms.php"]}
themes={"themes":{"acme-studio":{"Version":"1.9.0"}},"active":"acme-studio"}
```

The same object sent as a JSON body is accepted too. Plugins are keyed by
their `slug/file.php` path and matched by the directory; themes by their
stylesheet, which is the slug.

The response has the same shape as wordpress.org's:

```json
{
    "plugins": {
        "acme-forms/acme-forms.php": {
            "id": "acme-forms",
            "slug": "acme-forms",
            "plugin": "acme-forms/acme-forms.php",
            "new_version": "1.1.0",
            "url": "https://acme.test/forms",
            "package": "https://packages.example.com/wp/dist/acme-forms/1.1.0.zip",
            "requires": "6.4",
            "tested": "6.7",
            "requires_php": "8.2"
        }
    },
    "no_update": {},
    "translations": []
}
```

- A package with a newer release than the installed `Version` is under
  `plugins` (or `themes`, where each entry carries `theme` instead of `id`,
  `slug` and `plugin`).
- A package this registry serves that is already current is under
  `no_update`, with the same fields.
- **Anything this registry does not serve to WordPress is left out**, so
  wordpress.org can answer for it. That includes a served package that has no
  release with a WordPress zip yet.

`new_version` is the header `Version` of the newest release, not its tag,
because that is what WordPress compares with the installed header. The newest
release is the highest tagged version that is not an alpha, beta or RC and
that the token's subscription ceiling permits. Branches are never offered.
`url` is the header's `Plugin URI` or `Theme URI`.

### Information

```
GET /wp/plugins/info/1.2?action=plugin_information&request[slug]=acme-forms
GET /wp/themes/info/1.2?action=theme_information&request[slug]=acme-studio
```

returns what the plugin details modal and `wp plugin install` read:

```json
{
    "name": "Acme Forms",
    "slug": "acme-forms",
    "version": "1.1.0",
    "author": "<a href=\"https://acme.test\">Acme</a>",
    "homepage": "https://acme.test/forms",
    "download_link": "https://packages.example.com/wp/dist/acme-forms/1.1.0.zip",
    "requires": "6.4",
    "tested": "6.7",
    "requires_php": "8.2",
    "last_updated": "2026-09-01T12:00:00+00:00",
    "sections": {
        "description": "<p>Forms, by Acme.</p>",
        "changelog": "<h2>1.1.0</h2>\n<ul>\n<li>Added a <strong>date</strong> field.</li>\n</ul>\n"
    }
}
```

`sections.changelog` is the release's `CHANGELOG.md` rendered from Markdown,
with any raw HTML in it stripped; it is absent when the package has none. A
slug this registry does not serve is `404 {"error": "Plugin not found."}`
(or `Theme not found.`), and any other `action` is `400`.

## Pointing a site at the registry

WordPress core only ever asks wordpress.org, so a site needs a mu-plugin that
sends the questions about our slugs here instead. Aether owns the real one;
what follows is the reference it is built from. It reads everything from
constants in `wp-config.php`, so nothing about the registry, least of all the
token, is stored in the database:

```php
// wp-config.php
define('PACKAGE_PIPELINE_URL', 'https://packages.example.com'); // or https://packages.example.com/r/internal
define('PACKAGE_PIPELINE_TOKEN', 'pp_...');                     // a deploy token with repository:read
define('PACKAGE_PIPELINE_PLUGINS', ['acme-forms/acme-forms.php']);
define('PACKAGE_PIPELINE_THEMES', ['acme-studio']);
```

```php
<?php
/**
 * Plugin Name: Package Pipeline updates
 * Description: Answers update and information requests for the plugins and themes Package Pipeline serves.
 */

if (! defined('PACKAGE_PIPELINE_URL') || ! defined('PACKAGE_PIPELINE_TOKEN')) {
    return;
}

/**
 * Ask the registry, returning the decoded JSON or null on any failure. Arrays,
 * not objects: core keeps theme updates as arrays and casts plugin ones itself.
 */
function package_pipeline_request(string $method, string $path, ?array $body = null): ?array
{
    $response = wp_remote_request(untrailingslashit(PACKAGE_PIPELINE_URL).$path, [
        'method' => $method,
        'timeout' => 15,
        'headers' => [
            'Authorization' => 'Bearer '.PACKAGE_PIPELINE_TOKEN,
            'Accept' => 'application/json',
        ],
        'body' => $body,
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return null;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    return is_array($data) ? $data : null;
}

/**
 * Merge the registry's answer for our slugs into an update transient, after
 * removing whatever wordpress.org said about them: a public plugin that
 * happens to share one of our slugs must never be offered as its update.
 */
function package_pipeline_check(object $transient, string $kind, array $served): object
{
    if (empty($transient->checked) || $served === []) {
        return $transient;
    }

    $installed = array_intersect_key($transient->checked, array_flip($served));

    if ($installed === []) {
        return $transient;
    }

    foreach (array_keys($installed) as $key) {
        unset($transient->response[$key], $transient->no_update[$key]);
    }

    $answer = package_pipeline_request('POST', "/wp/{$kind}/update-check/1.1", [
        $kind => wp_json_encode([
            $kind => array_map(fn (string $version): array => ['Version' => $version], $installed),
            'active' => [],
        ]),
    ]);

    if ($answer === null) {
        return $transient;
    }

    // Plugin entries are objects in the transient; theme entries are arrays.
    $shape = $kind === 'plugins' ? fn (array $entry): object => (object) $entry : fn (array $entry): array => $entry;

    foreach ($answer[$kind] ?? [] as $key => $entry) {
        $transient->response[$key] = $shape($entry);
    }

    foreach ($answer['no_update'] ?? [] as $key => $entry) {
        $transient->no_update[$key] = $shape($entry);
    }

    return $transient;
}

add_filter('pre_set_site_transient_update_plugins', fn ($transient) => is_object($transient)
    ? package_pipeline_check($transient, 'plugins', defined('PACKAGE_PIPELINE_PLUGINS') ? PACKAGE_PIPELINE_PLUGINS : [])
    : $transient);

add_filter('pre_set_site_transient_update_themes', fn ($transient) => is_object($transient)
    ? package_pipeline_check($transient, 'themes', defined('PACKAGE_PIPELINE_THEMES') ? PACKAGE_PIPELINE_THEMES : [])
    : $transient);

/**
 * The details modal and `wp plugin install <slug>` for our slugs.
 */
add_filter('plugins_api', function ($result, $action, $args) {
    $slugs = array_map('dirname', defined('PACKAGE_PIPELINE_PLUGINS') ? PACKAGE_PIPELINE_PLUGINS : []);

    if ($action !== 'plugin_information' || ! in_array($args->slug ?? null, $slugs, true)) {
        return $result;
    }

    $info = package_pipeline_request('GET', '/wp/plugins/info/1.2?'.http_build_query([
        'action' => 'plugin_information',
        'request' => ['slug' => $args->slug],
    ]));

    return $info === null
        ? new WP_Error('plugins_api_failed', "Package Pipeline did not answer for {$args->slug}.")
        : (object) $info;
}, 10, 3);

add_filter('themes_api', function ($result, $action, $args) {
    $slugs = defined('PACKAGE_PIPELINE_THEMES') ? PACKAGE_PIPELINE_THEMES : [];

    if ($action !== 'theme_information' || ! in_array($args->slug ?? null, $slugs, true)) {
        return $result;
    }

    $info = package_pipeline_request('GET', '/wp/themes/info/1.2?'.http_build_query([
        'action' => 'theme_information',
        'request' => ['slug' => $args->slug],
    ]));

    return $info === null
        ? new WP_Error('themes_api_failed', "Package Pipeline did not answer for {$args->slug}.")
        : (object) $info;
}, 10, 3);

/**
 * The zip download is made by core's upgrader, which knows nothing about the
 * token: add it for our dist URLs, and only for them.
 */
add_filter('http_request_args', function (array $args, string $url): array {
    if (str_starts_with($url, untrailingslashit(PACKAGE_PIPELINE_URL).'/wp/dist/')) {
        $args['headers']['Authorization'] = 'Bearer '.PACKAGE_PIPELINE_TOKEN;
    }

    return $args;
}, 10, 2);
```

A few things worth knowing about it:

- **Core sets the update transient twice per check** (once to mark the check
  as started, once with wordpress.org's answer), so the filter asks the
  registry twice. Harmless, and the second answer is the one kept; cache the
  answer in a static for the request if it matters.
- **A registry that cannot be reached leaves our slugs with no update**, never
  with wordpress.org's, because their entries are removed before asking.
- **Single-file plugins** (`hello.php` with no directory) cannot be served:
  the slug is the directory.
- On WordPress 5.8 and later a plugin can instead declare an `Update URI:`
  header naming this registry, which keeps core from asking wordpress.org
  about it at all; the `update_plugins_{hostname}` filter is then the place to
  call the update check. The transient filters above work without touching
  the plugin's header, which is why they are the reference.

## Provisioning a site

A provisioning job installs a release by slug and version without going
through WordPress at all. Ask for the version you want (or ask the update
check what is newest), download the zip, and verify its sha1 before unpacking.

The sha1 and size are recorded per version. The management API returns them
under each version's `wordpress` key (`version` is the header version), from
`GET /api/v1/packages/{id}` with an `api:read` token:

```json
{"version": "1.1.0", "shasum": "…", "wordpress": {"version": "1.1.0", "shasum": "3f78…", "size": 48213}}
```

This is not the version's top-level `shasum`, which is the Composer archive's.
Then:

```bash
curl -fsSL -H "Authorization: Bearer $PACKAGE_PIPELINE_TOKEN" \
  -o acme-forms.zip \
  "https://packages.example.com/wp/dist/acme-forms/1.1.0.zip"

echo "$EXPECTED_SHA1  acme-forms.zip" | sha1sum -c -

wp plugin install ./acme-forms.zip --activate
```

`wp plugin install` takes the zip's single top-level directory as the plugin
directory, which is why it is the slug. A theme is the same with
`wp theme install`.

To find the newest release without the API, post the site's current versions
(or `0` for a fresh install) to the update check and use `new_version` and
`package` from the answer:

```bash
curl -fsS -H "Authorization: Bearer $PACKAGE_PIPELINE_TOKEN" \
  --data-urlencode 'plugins={"plugins":{"acme-forms/acme-forms.php":{"Version":"0"}}}' \
  https://packages.example.com/wp/plugins/update-check/1.1
```

## Not implemented

Say so before relying on any of these:

- Plugins and themes without a `composer.json` at each tag.
- Changelogs from annotated tag messages; only `CHANGELOG.md` is read.
- Icons, banners, screenshots, ratings, and the other plugin card fields
  wordpress.org serves.
- Translations: `translations` is always empty.
- `archives:audit` does not yet check the WordPress zips. A lost one answers
  `404` until `package:rebuild` stores it again.
