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

];
