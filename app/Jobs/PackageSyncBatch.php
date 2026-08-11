<?php

namespace App\Jobs;

use App\Models\Package;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

/**
 * A package sync as a queued job batch: one DiscoverVersions job that fans out
 * one ImportVersion per ref needing work, closed out by FinalizePackageSync.
 *
 * allowFailures() is the point of the shape: importing a repository with two
 * hundred tags is two hundred jobs, and one broken tag costs one job rather
 * than the sync. The batch id is stored on the package so the panel can show
 * progress while the imports run.
 */
class PackageSyncBatch
{
    public static function dispatchFor(Package $package, bool $force = false): Batch
    {
        // What "changed" means afterwards is measured against what was stored
        // when the sync began, captured here because the batch's callbacks
        // only ever see the end state.
        $known = array_map(strval(...), $package->versions()->pluck('version')->all());

        $batch = Bus::batch([new DiscoverVersions($package, $force)])
            ->name(($force ? 'Rebuild' : 'Sync')." {$package->name}")
            ->allowFailures()
            ->finally(new FinalizePackageSync((int) $package->getKey(), $known))
            ->dispatch();

        // Only the batch id is dirty, so this cannot clobber columns the
        // batch itself already rewrote (on the sync driver it has run and
        // finished before this line). Which batch is rebuilding a package is
        // bookkeeping — it says nothing about what the package publishes — and
        // the hourly schedule dispatches one per package, so writing it loudly
        // would move every metadata validator in the registry on the hour.
        $package->recordBookkeeping(['sync_batch_id' => $batch->id]);

        return $batch;
    }
}
