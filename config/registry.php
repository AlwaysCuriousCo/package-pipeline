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

];
