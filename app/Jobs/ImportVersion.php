<?php

namespace App\Jobs;

use App\Exceptions\RateLimited;
use App\Models\Package;
use App\Services\PackageSynchronizer;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Import one ref of one package, as part of a sync batch.
 *
 * The batch allows failures, so a tag whose zipball is broken costs exactly
 * that tag: every other job still runs, and FinalizePackageSync counts the
 * casualties on the package afterwards.
 */
class ImportVersion implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    /**
     * Room to stream a large archive; a whole repository no longer has to fit
     * in one job's timeout, only its biggest version.
     */
    public int $timeout = 300;

    /**
     * Deleting a package while its imports are still queued is not a failure.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Package $package,
        public string $version,
        public string $reference,
        public bool $isDev,
        public bool $force = false,
    ) {}

    /**
     * Wait out a GitHub hiccup rather than retrying straight into it.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(PackageSynchronizer $synchronizer): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        try {
            $synchronizer->import($this->package, $this->version, [
                'reference' => $this->reference,
                'is_dev' => $this->isDev,
            ], $this->force);
        } catch (RateLimited $limited) {
            // A rebuild of a large repository is exactly what trips a
            // provider's limits — three API calls per ref, times every ref —
            // so this is the job most likely to meet one. Waiting the limit
            // out costs the batch nothing but time; failing it costs the
            // package a version until the next sync. The reason is left on
            // the package because a batch full of released imports is
            // otherwise a sync that has simply stopped, with nothing said.
            $this->package->forceFill(['sync_error' => $limited->getMessage()])->save();

            $this->release($limited->retryAt);
        }
    }
}
