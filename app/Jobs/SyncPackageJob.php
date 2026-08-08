<?php

namespace App\Jobs;

use App\Models\Package;
use App\Notifications\PackageSyncFailed;
use App\Services\AdminNotifier;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Start one package's sync, off the request.
 *
 * The sync itself runs as a job batch (PackageSyncBatch): discovery fans out
 * one import job per ref, so this job's own work is only to start it — but it
 * stays the single entry point because it is what carries the debounce and
 * uniqueness rules every caller (panel, console, webhooks) relies on.
 */
class SyncPackageJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * Dispatching the batch is a couple of writes; re-running it is safe
     * because the batch's own jobs are idempotent per ref.
     */
    public int $tries = 3;

    /**
     * Starting the batch does not read GitHub at all — the batch's jobs carry
     * their own timeouts for that.
     */
    public int $timeout = 60;

    /**
     * How long a rebuild may sit unfinished before it is presumed lost and a
     * new sync may start over it. @see handle()
     */
    public const STALE_BATCH_HOURS = 2;

    /**
     * Deleting a package while its sync is still queued is not a failure.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Release the uniqueness lock after an hour no matter what, so a worker
     * lost between dispatch and processing cannot wedge a package's syncs.
     */
    public int $uniqueFor = 3600;

    /**
     * How long a push-triggered sync waits before it runs. @see debounced()
     */
    public const DEBOUNCE_SECONDS = 15;

    public function __construct(public Package $package, public bool $force = false) {}

    /**
     * Wait between attempts rather than retrying straight into whatever
     * failed. GitHub's rate limit resets on the hour, and its outages tend to
     * outlast a few seconds, so the last gap is minutes rather than seconds.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * At most one *pending* sync per package, so a double-clicked Sync button
     * queues the work once instead of twice.
     */
    public function uniqueId(): string
    {
        return (string) $this->package->getKey();
    }

    /**
     * ShouldBeUniqueUntilProcessing releases its lock the moment the job
     * starts, which is what lets a push made mid-sync be picked up by a
     * freshly queued one. WithoutOverlapping closes the window that opens up:
     * two syncs of the same package must still never rebuild its versions
     * concurrently, or the loser's prune deletes rows the winner just wrote.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping((string) $this->package->getKey()))
                ->releaseAfter(60)
                ->expireAfter($this->timeout + 60),
        ];
    }

    /**
     * A sync queued in response to a push, held back briefly so that a burst
     * of deliveries becomes one sync.
     *
     * `git push --tags` on ten tags is ten deliveries in about a second, and a
     * release pipeline that tags and then pushes a branch is several more. The
     * uniqueness lock above is held for as long as the job sits in the queue,
     * so a short delay lets every delivery in that burst collapse into the one
     * run that was going to read the whole repository anyway.
     */
    public static function debounced(Package $package): void
    {
        self::dispatch($package)->delay(now()->addSeconds(self::DEBOUNCE_SECONDS));
    }

    /**
     * Queue a sync now, reporting whether one was actually queued.
     *
     * dispatch() drops a duplicate of a unique job silently, which is exactly
     * right for a webhook burst but lets the panel claim "queued" while a
     * debounced sync is already holding the lock. Taking the lock here first
     * — the same lock dispatch() would take — makes the drop observable; the
     * job then goes straight to the dispatcher, which performs no second
     * uniqueness check.
     */
    public static function dispatchUnlessPending(Package $package, bool $force = false): bool
    {
        $job = new self($package, $force);
        $lock = new UniqueLock(app(Cache::class));

        if (! $lock->acquire($job)) {
            return false;
        }

        try {
            app(Dispatcher::class)->dispatch($job);
        } catch (Throwable $exception) {
            // No job made it onto the queue, so nothing downstream will ever
            // release the lock — held, it would answer every sync for the
            // next hour with "already queued".
            $lock->release($job);

            throw $exception;
        }

        return true;
    }

    public function handle(): void
    {
        // Two rebuilds of one package must never run concurrently, or the
        // loser's prune deletes rows the winner just wrote. WithoutOverlapping
        // cannot see the batch — it only guards this job — so a sync arriving
        // while a batch is still importing steps aside and comes back once it
        // is done, exactly as a push made mid-sync always has. A batch that
        // has sat unfinished for hours is a worker lost mid-run, not a sync in
        // progress, and must not wedge the package forever.
        $batch = $this->package->syncBatch();

        if ($batch !== null && $this->package->syncRunning()) {
            if ($batch->createdAt->gt(now()->subHours(self::STALE_BATCH_HOURS))) {
                self::dispatch($this->package, $this->force)->delay(now()->addSeconds(30));

                return;
            }

            // Presumed lost, but its import jobs may still be sitting on the
            // queue; cancelled, they no-op instead of racing the new batch's
            // prune and imports.
            $batch->cancel();
        }

        PackageSyncBatch::dispatchFor($this->package, $this->force);
    }

    /**
     * The batch's own jobs record their failure reasons, but this job can die
     * before the batch exists at all — a queue connection refusing, a worker
     * killed mid-dispatch. Leave a reason in the column the panel reads.
     */
    public function failed(?Throwable $exception): void
    {
        $reason = $exception?->getMessage() ?: 'The sync failed without reporting a reason.';

        $this->package->forceFill(['sync_error' => $reason])->save();

        // Only once the retries are spent. A package that stops syncing stops
        // receiving releases, and nothing else would say so out loud.
        app(AdminNotifier::class)->send(new PackageSyncFailed($this->package, $reason));
    }
}
