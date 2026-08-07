<?php

namespace App\Jobs;

use App\Models\Package;
use App\Notifications\PackageSyncFailed;
use App\Notifications\PackageVersionsPublished;
use App\Services\AdminNotifier;
use App\Services\PackageSynchronizer;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Rebuild one package's versions from its repository, off the request.
 *
 * A first sync reads two GitHub endpoints per version, which is far too long
 * to hold an HTTP request open, so the panel and the console command both hand
 * the work here rather than running it inline.
 */
class SyncPackageJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /**
     * A sync reads GitHub and then writes in a single transaction, so a
     * partial run leaves nothing behind and re-running is safe.
     */
    public int $tries = 3;

    /**
     * The default 60 seconds only covers a repository with a handful of
     * versions. Give a first import of a long-lived one room to finish.
     */
    public int $timeout = 600;

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

    public function __construct(public Package $package) {}

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
    public static function dispatchUnlessPending(Package $package): bool
    {
        $job = new self($package);

        if (! (new UniqueLock(app(Cache::class)))->acquire($job)) {
            return false;
        }

        app(Dispatcher::class)->dispatch($job);

        return true;
    }

    public function handle(PackageSynchronizer $synchronizer, AdminNotifier $notifier): void
    {
        $outcome = $synchronizer->sync($this->package);

        // Dev versions move on every commit and removals are usually a branch
        // being tidied up, so a release — or a package arriving for the first
        // time — is all that is worth anyone's attention.
        if ($outcome->releases === [] && ! $outcome->initialImport) {
            return;
        }

        $notifier->send(new PackageVersionsPublished($this->package, $outcome));
    }

    /**
     * The synchronizer records its own failure reason before rethrowing, but a
     * job can die without ever reaching it — a timeout, or a worker killed
     * mid-run. Leave a reason in the column the panel reads either way.
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
