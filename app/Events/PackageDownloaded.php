<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A dist archive was served.
 *
 * Carries scalars rather than models: the recording listener runs on the
 * queue, and by then the version row may have been pruned — the download
 * still happened and must still count.
 */
class PackageDownloaded
{
    use Dispatchable;

    public function __construct(
        public int $packageId,
        public ?int $packageVersionId,
        public string $version,
        public ?string $tokenPrefix,
    ) {}
}
