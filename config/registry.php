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
    | size. The upload ceiling above bounds what a token of ours may spend; this
    | bounds what a *stranger's* published package can, since any name a
    | consuming project requires can reach it.
    |
    */

    'mirror' => [
        'metadata_ttl_minutes' => (int) env('MIRROR_METADATA_TTL_MINUTES', 60),
        'missing_ttl_minutes' => (int) env('MIRROR_MISSING_TTL_MINUTES', 10),
        'retention_days' => (int) env('MIRROR_RETENTION_DAYS', 30),
        'max_archive_megabytes' => (int) env('MIRROR_MAX_ARCHIVE_MB', 256),
    ],

];
