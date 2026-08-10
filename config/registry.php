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
    | `egress` bounds where a mirrored fetch may go. A `dist.url` is written by
    | whoever published the package upstream, so on a repository mirroring
    | packagist.org it is written by the general public — and a registry that
    | fetched one unconditionally would dial any address a stranger named.
    | Anything but the upstream's own origin is therefore held to public
    | addresses, and these two are the way out of that for the installation
    | whose self-hosted upstream genuinely names an internal object store:
    | `allow_private` turns the address rules off wholesale, `allowed_hosts`
    | names the hosts they do not apply to. See App\Services\Mirror\EgressPolicy.
    |
    */

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

    'mirror' => [
        'metadata_ttl_minutes' => (int) env('MIRROR_METADATA_TTL_MINUTES', 60),
        'missing_ttl_minutes' => (int) env('MIRROR_MISSING_TTL_MINUTES', 10),
        'advisory_ttl_minutes' => (int) env('MIRROR_ADVISORY_TTL_MINUTES', 10),
        'failure_backoff_minutes' => (int) env('MIRROR_FAILURE_BACKOFF_MINUTES', 5),
        'retention_days' => (int) env('MIRROR_RETENTION_DAYS', 30),
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
