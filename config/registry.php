<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Artifact Upload Ceiling
    |--------------------------------------------------------------------------
    |
    | The largest zip POST /upload/{vendor}/{package} will accept, in
    | megabytes. A published Composer package is a source tree: the fattest on
    | Packagist are tens of megabytes, so 100 leaves an order of magnitude of
    | headroom while still bounding what one write-capable token can spend of a
    | worker's temp disk, its hashing time and the dist bucket.
    |
    | Raise it for a deployment that publishes vendored monorepo builds — but
    | PHP's own upload_max_filesize and post_max_size have to be raised with
    | it, or PHP discards the body before the app ever sees it.
    |
    */

    'upload_max_megabytes' => (int) env('ARTIFACT_UPLOAD_MAX_MB', 100),

    /*
    |--------------------------------------------------------------------------
    | Composer Repository Key
    |--------------------------------------------------------------------------
    |
    | The name of the entry the install instructions tell a consuming project to
    | add under `repositories` in its composer.json, used exactly as it is
    | written here: set to "alwayscurious" the panel offers
    | `composer config repositories.alwayscurious composer ...` for every
    | repository, whatever each one's path is.
    |
    | Empty, it is derived from the application name instead, with a named
    | repository's path appended — which is the right default, because it is
    | what keeps two mounts on one registry from claiming the same entry in a
    | consumer's composer.json. Setting this takes that guarantee on: an
    | installation serving several repositories under one fixed key is telling
    | consumers to overwrite one entry with another. Set it for the single-
    | repository installation whose registry is known by a different name than
    | its panel is, and leave it alone otherwise.
    |
    */

    'composer_repository_key' => env('COMPOSER_REPOSITORY_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Rendered Metadata Cache
    |--------------------------------------------------------------------------
    |
    | The /p2 endpoint stores the exact bytes it serves, keyed by a fingerprint
    | of the version rows behind them. A sync invalidates an entry by producing
    | a different key rather than by clearing anything, so no write path has to
    | remember this cache exists.
    |
    | Entries therefore supersede themselves, and the lifetime only decides how
    | long a superseded one lingers in the store. A week keeps the store small
    | without costing a rebuild for a package that is only fetched now and then.
    |
    | The ceiling is a sanity bound rather than a tuning knob: the default store
    | is the database, where the value column is a MEDIUMTEXT and every hit
    | drags the whole row across the wire. A payload past it is served from the
    | version rows every time, which for a package that fat is the lesser
    | problem. Zero turns the cache off entirely.
    |
    */

    'metadata_cache' => [
        'days' => (int) env('METADATA_CACHE_DAYS', 7),
        'max_kilobytes' => (int) env('METADATA_CACHE_MAX_KB', 4096),
    ],

    /*
    |--------------------------------------------------------------------------
    | Download History Retention
    |--------------------------------------------------------------------------
    |
    | `downloads` gains a row for every zip the registry serves, which makes it
    | the fastest-growing table in the schema by a wide margin. `downloads:prune`
    | is what bounds it: rows older than this are counted into the packages' and
    | versions' `pruned_downloads` and then deleted.
    |
    | What that costs is the *detail* — which credential fetched which version,
    | and on which day. The `total_downloads` counters are unaffected, and so is
    | `downloads:recalculate`, which adds the pruned tally back. What goes is the
    | ability to chart or export beyond the window, so size this against how far
    | back anyone actually asks. The panel charts thirty days on the dashboard
    | and ninety on a package; 400 leaves a year-over-year comparison intact and
    | absorbs a monthly warehouse extract that did not run.
    |
    | Zero keeps everything forever, which is the right answer for an
    | installation that would rather manage the growth itself — but it is growth
    | with no ceiling, so it is a decision rather than a default.
    |
    */

    'downloads' => [
        'retention_days' => (int) env('DOWNLOAD_RETENTION_DAYS', 400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Upstream Mirroring
    |--------------------------------------------------------------------------
    |
    | Nothing here turns mirroring on — that is a per-repository decision, made
    | by adding an upstream in the admin panel, and an installation with none
    | never reaches any of this. These are the numbers that apply once one has.
    |
    | `metadata_ttl_minutes` is how long a cached upstream document is served
    | without asking the upstream anything. Past it the next request revalidates
    | with If-None-Match / If-Modified-Since, which for an unchanged package is
    | a 304 with no body — so this trades staleness against a round trip, not
    | against bandwidth. An hour is well inside how long a new release takes to
    | propagate through Composer's own caches.
    |
    | `missing_ttl_minutes` is the same idea for a name the upstream does not
    | have. It has to be much shorter, because a package published a minute ago
    | is exactly the one somebody is waiting on — but it cannot be zero, or a
    | typo in a composer.json becomes an upstream request on every resolve.
    |
    | `retention_days` is what `mirror:prune` enforces: a cached document or
    | archive nothing has asked for in this long is deleted, and is re-fetched
    | for free the next time it is wanted. This is the only bound on what the
    | dist disk grows to, so it is the number to lower when disk is tight.
    |
    | `max_archive_megabytes` refuses to cache an upstream archive past this
    | size, and `max_metadata_kilobytes` does the same for a metadata document.
    | The upload ceiling above bounds what a token of ours may spend; these
    | bound what a *stranger's* published package can, since any name a
    | consuming project requires can reach them.
    |
    | `advisory_ttl_minutes` is how long an upstream's answer about known
    | vulnerabilities is reused. An audit runs inside every `composer update`,
    | so without it a CI fleet puts one upstream request per build in front of
    | the upstream — and the feed behind that answer is itself hours old.
    |
    | `failure_backoff_minutes` is how long an upstream that has just failed is
    | left alone. During it, cached documents still answer and nothing reaches
    | the network. Without it an outage costs every mirrored lookup a connect
    | timeout, so one `composer update` spends minutes discovering the same
    | thing once per dependency — with a PHP worker held open for each.
    |
    | `lock_wait_seconds` is how long a request waits for another process that
    | is already fetching the same thing. Only the first request for a cold
    | package or archive fetches it; the rest wait for that one and are served
    | its answer, which is what keeps a CI fleet starting fifty builds at once
    | from making fifty identical downloads. A wait that runs out is not an
    | error — a metadata lookup falls back to whatever is cached, and an
    | archive is fetched again rather than 404ing a build.
    |
    | `egress` bounds where a mirrored fetch may go. A `dist.url` is written by
    | whoever published the package upstream, so on a repository mirroring
    | packagist.org it is written by the general public — and a registry that
    | fetched one unconditionally would dial any address a stranger named.
    | Anything but the upstream's own origin is therefore held to public
    | addresses, and these two are the way out of that for the installation
    | whose self-hosted upstream genuinely names an internal object store:
    | `allow_private` turns the address rules off wholesale, `allowed_hosts`
    | names the hosts they do not apply to. See App\Support\EgressPolicy.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Outgoing Webhook Destinations
    |--------------------------------------------------------------------------
    |
    | The same guard, applied to where a delivery may be POSTed, and separate
    | from the mirror's on purpose. An endpoint URL is typed by whoever holds
    | the permission to create one — which is a panel permission, not shell
    | access — and both the response status and the first two hundred
    | characters of the receiver's body come back into the panel. Left
    | unbounded that is a port scanner with a readout: `http://10.0.0.1:8500/`
    | and `http://169.254.169.254/latest/meta-data/` are URLs like any other.
    |
    | So private, loopback and link-local destinations are refused by default,
    | at save time and again at every delivery (including each redirect hop).
    | A pipeline that genuinely lives on an internal network is the reason
    | these exist: `WEBHOOK_ALLOW_PRIVATE_ENDPOINTS` turns the address rules
    | off wholesale, `WEBHOOK_PRIVATE_HOSTS` names the hosts they do not apply
    | to. Both are environment settings rather than fields on the form,
    | because the decision belongs to whoever deployed this app.
    |
    */

    'webhooks' => [
        'egress' => [
            'allow_private' => (bool) env('WEBHOOK_ALLOW_PRIVATE_ENDPOINTS', false),
            'allowed_hosts' => array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) env('WEBHOOK_PRIVATE_HOSTS', '')),
            ))),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin Notifications By Email
    |--------------------------------------------------------------------------
    |
    | Releases, failed syncs and abandonments already reach the panel's bell,
    | the Slack channel and every subscribed outgoing webhook. This adds email
    | to that fan-out, addressed to each panel user who holds a role.
    |
    | Off by default, and for a plainer reason than the metrics endpoint below:
    | mail is the one channel here that needs a working provider behind it.
    | While `MAIL_MAILER=log` is set — the shipped default — turning this on
    | writes every announcement to the log file and delivers nothing, so the
    | switch would be a trap on a fresh install rather than a feature. Turn it
    | on in the same change that configures a mailer, not before.
    |
    | It is also the noisiest channel by some distance. A bell notification is
    | read when somebody opens the panel; an email interrupts. An installation
    | syncing thirty packages can publish a dozen releases in an afternoon,
    | which is a fine Slack channel and a poor inbox — which is why each user
    | has their own switch on the profile page, and why this one only decides
    | whether that switch exists at all. Enabled here, users are opted in and
    | may opt out; disabled, no mail is sent whatever a user's row says and the
    | profile section is not rendered.
    |
    */

    'notifications' => [
        'mail' => (bool) env('MAIL_ADMIN_NOTIFICATIONS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prometheus Metrics
    |--------------------------------------------------------------------------
    |
    | A scrape endpoint at /metrics, answering registry totals, sync health,
    | queue depth and — when a repository mirrors — what the cache is holding.
    | It is what `/up` cannot be: that endpoint proves the container boots and
    | touches neither the session nor the database, which makes it a liveness
    | probe and nothing more.
    |
    | Off by default, and that is a decision rather than caution. Metrics
    | endpoints are conventionally unauthenticated because they conventionally
    | sit on an internal network — but a Composer registry is routinely
    | published to the internet so CI can reach it, and an on-by-default
    | endpoint would tell any passer-by how many private packages exist, how
    | much traffic they get and whether the queue is stuck. Enabling it is one
    | line; widening an existing installation's exposure on upgrade is not
    | something a release should do quietly.
    |
    | `token` is then optional. Set, it is a bearer token every scrape must
    | present. Left empty, the endpoint is open — the right answer when the
    | port genuinely is not routable, and the wrong one whenever there is doubt.
    |
    | `cache_seconds` is how long one rendered document is reused. Two
    | Prometheus replicas scraping the same instance is the ordinary
    | deployment, and the second should cost a cache read. This bounds
    | resolution rather than correctness: Prometheus timestamps a sample when it
    | collects it. Sized just under the conventional 15-second scrape interval
    | so consecutive scrapes of one replica still each do the work. Zero turns
    | the cache off.
    |
    */

    'metrics' => [
        'enabled' => (bool) env('METRICS_ENABLED', false),
        'token' => env('METRICS_TOKEN'),
        'cache_seconds' => (int) env('METRICS_CACHE_SECONDS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public Package Pages
    |--------------------------------------------------------------------------
    |
    | Nothing here publishes anything. A page exists because an admin switched
    | one on for a package or a repository; these are the numbers and the
    | defaults that apply once one has.
    |
    | `page_image` is the social preview image used for every page that has not
    | set one of its own — an absolute URL, or a path relative to this app's
    | root. It is what appears when a page's URL is pasted into Slack, a post
    | or a chat, so an installation that sets nothing else should set this: a
    | link with no card beside it is a link most people do not click.
    |
    | `pages.markdown_cache_minutes` is how long a rendered README is reused.
    | Rendering is CommonMark over a document nobody edits between syncs, so
    | this is pure repetition avoided; entries are keyed by a hash of the
    | markdown itself, so a sync that changes the file changes the key and
    | nothing has to remember to clear anything. Zero turns the cache off.
    |
    | `pages.max_body_kilobytes` refuses to store or render a page body past
    | this size. The body comes out of somebody else's repository, and a
    | README is prose — a file orders of magnitude past that is a generated
    | artifact, a vendored document or a mistake, and rendering it costs this
    | app's memory per visitor rather than per sync.
    |
    | `pages.asset_cache_minutes` is how long an image fetched out of a
    | package's repository is kept. A page's screenshots are re-served by this
    | app rather than linked to the provider — the only way a private
    | repository's images render for a reader who has no credential for it, and
    | the only way a link unfurler can fetch a card image — so without a cache
    | every visitor would cost a provider request. Entries are keyed by the ref
    | as well as the path, so a release that changes an image publishes the new
    | one without anything being cleared. Zero turns the cache off, which means
    | one provider request per image per visitor.
    |
    | `pages.max_asset_kilobytes` refuses to serve or cache an image past this
    | size. It comes out of somebody's repository, and a repository is allowed
    | to contain a 40MB PSD with a .png extension.
    |
    | `pages.sitemap` publishes /sitemap.xml and /robots.txt listing every
    | enabled page, which is what gets them indexed. Off leaves the pages
    | reachable and unlisted — the right answer for a registry whose pages are
    | for people who were sent the link rather than for search.
    |
    */

    'page_image' => env('PAGE_IMAGE'),

    'pages' => [
        'markdown_cache_minutes' => (int) env('PAGE_MARKDOWN_CACHE_MINUTES', 1440),
        'max_body_kilobytes' => (int) env('PAGE_MAX_BODY_KB', 512),
        'asset_cache_minutes' => (int) env('PAGE_ASSET_CACHE_MINUTES', 1440),
        'max_asset_kilobytes' => (int) env('PAGE_MAX_ASSET_KB', 4096),
        'sitemap' => (bool) env('PAGE_SITEMAP', true),
    ],

    'mirror' => [
        'metadata_ttl_minutes' => (int) env('MIRROR_METADATA_TTL_MINUTES', 60),
        'missing_ttl_minutes' => (int) env('MIRROR_MISSING_TTL_MINUTES', 10),
        'advisory_ttl_minutes' => (int) env('MIRROR_ADVISORY_TTL_MINUTES', 10),
        'failure_backoff_minutes' => (int) env('MIRROR_FAILURE_BACKOFF_MINUTES', 5),
        'retention_days' => (int) env('MIRROR_RETENTION_DAYS', 30),
        'lock_wait_seconds' => (int) env('MIRROR_LOCK_WAIT_SECONDS', 10),
        'max_archive_megabytes' => (int) env('MIRROR_MAX_ARCHIVE_MB', 256),
        'max_metadata_kilobytes' => (int) env('MIRROR_MAX_METADATA_KB', 8192),
        'egress' => [
            'allow_private' => (bool) env('MIRROR_ALLOW_PRIVATE_DIST_HOSTS', false),
            'allowed_hosts' => array_values(array_filter(array_map(
                trim(...),
                explode(',', (string) env('MIRROR_PRIVATE_DIST_HOSTS', '')),
            ))),
        ],
    ],

];
