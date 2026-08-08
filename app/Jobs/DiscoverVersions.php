<?php

namespace App\Jobs;

use App\Models\Package;
use App\Notifications\PackageSyncFailed;
use App\Services\AdminNotifier;
use App\Services\PackageSynchronizer;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * The first job of a sync batch: list what the repository publishes, drop what
 * it no longer does, and fan out one ImportVersion job per ref that needs
 * work. Refs whose sha has not moved add no job at all, so a routine sync is
 * a batch of nothing but this.
 */
class DiscoverVersions implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    /**
     * Listing refs is paginated API reads, not archive downloads; even a
     * repository at the pagination ceiling fits well inside this.
     */
    public int $timeout = 300;

    /**
     * Deleting a package while its sync is still queued is not a failure.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Package $package, public bool $force = false) {}

    /**
     * Wait between attempts rather than retrying straight into whatever
     * failed — GitHub's outages tend to outlast a few seconds.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(PackageSynchronizer $synchronizer): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        try {
            $refs = $synchronizer->discover($this->package);

            $synchronizer->resolveComposerName($this->package);
            $synchronizer->prune($this->package, array_map(strval(...), array_keys($refs)));

            $changed = $synchronizer->changed($this->package, $refs, $this->force);
        } catch (Throwable $exception) {
            // Every attempt leaves the reason where the panel reads it, not
            // just the one that exhausts the retries.
            $this->package->forceFill(['sync_error' => $exception->getMessage()])->save();

            throw $exception;
        }

        $imports = [];

        foreach ($changed as $version => $ref) {
            $imports[] = new ImportVersion($this->package, (string) $version, $ref['reference'], $ref['is_dev'], $this->force);
        }

        if ($imports !== []) {
            $this->batch()->add($imports);
        }
    }

    /**
     * Discovery failing means the sync as a whole failed: nothing was listed,
     * so nothing will import. Cancel the batch so FinalizePackageSync does not
     * stamp the package as freshly synced, and say so out loud — a package
     * that stops syncing stops receiving releases.
     */
    public function failed(?Throwable $exception): void
    {
        $reason = $exception?->getMessage() ?: 'The sync failed without reporting a reason.';

        $this->package->forceFill(['sync_error' => $reason])->save();

        $this->batch()?->cancel();

        app(AdminNotifier::class)->send(new PackageSyncFailed($this->package, $reason));
    }
}
