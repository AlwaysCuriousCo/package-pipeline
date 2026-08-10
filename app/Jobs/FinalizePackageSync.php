<?php

namespace App\Jobs;

use App\Models\Package;
use App\Notifications\PackageSyncFailed;
use App\Notifications\PackageVersionsPublished;
use App\Services\AdminNotifier;
use App\Services\PackageSynchronizer;
use Illuminate\Bus\Batch;
use Throwable;

/**
 * The sync batch's `finally` callback: once every import has run — succeeded
 * or not — refresh the package's own columns, and announce a release or a
 * package arriving for the first time.
 *
 * Serialized with the batch, so it carries only scalars: the package id, and
 * the versions the package had when the batch was dispatched, which is what
 * "what changed" is measured against.
 */
class FinalizePackageSync
{
    /**
     * @param  list<string>  $known
     */
    public function __construct(
        public int $packageId,
        public array $known,
    ) {}

    public function __invoke(Batch $batch): void
    {
        // A cancelled batch is a failed discovery: DiscoverVersions::failed()
        // has already recorded the reason and raised the alarm, and stamping
        // last_synced_at here would contradict it.
        if ($batch->cancelled()) {
            return;
        }

        $package = Package::query()->find($this->packageId);

        if (! $package instanceof Package) {
            return;
        }

        $attempted = max(0, $batch->totalJobs - 1);

        try {
            $outcome = app(PackageSynchronizer::class)->finalize($package, $this->known, $attempted, $batch->failedJobs);
        } catch (Throwable $exception) {
            $package->recordBookkeeping(['sync_error' => $exception->getMessage()]);

            app(AdminNotifier::class)->send(new PackageSyncFailed($package, $exception->getMessage()));

            return;
        }

        // Dev versions move on every commit and removals are usually a branch
        // being tidied up, so a release — or a package arriving for the first
        // time — is all that is worth anyone's attention.
        if ($outcome->releases === [] && ! $outcome->initialImport) {
            return;
        }

        app(AdminNotifier::class)->send(new PackageVersionsPublished($package, $outcome));
    }
}
