<?php

namespace App\Services;

use App\Jobs\SyncPackageJob;
use App\Models\Package;

/**
 * The recovery hammer: re-import every version from the source, trusting
 * nothing stored — metadata re-read, archives re-downloaded, versions gone
 * upstream pruned.
 *
 * It is a forced sync, so the package keeps serving its current versions
 * while the rebuild replaces them row by row, and running it twice settles
 * on the same result. For webhook drift, normalizer changes, corrupted
 * archives — anything where "what is stored" can no longer be trusted.
 */
class RebuildPackage
{
    public function __construct(private readonly PackageSynchronizer $synchronizer) {}

    /**
     * Rebuild inline, for the console and tests.
     */
    public function rebuild(Package $package): SyncOutcome
    {
        return $this->synchronizer->sync($package, force: true);
    }

    /**
     * Queue the rebuild through the ordinary sync pipeline — batch fan-out,
     * uniqueness, overlap rules and all. Reports whether one was actually
     * queued or a sync was already pending.
     */
    public function queue(Package $package): bool
    {
        return SyncPackageJob::dispatchUnlessPending($package, force: true);
    }
}
