<?php

namespace App\Services;

use App\Models\Package;
use App\Notifications\PackageSyncFailed;

/**
 * Announces a package's sync failing — once per failure, rather than once per
 * attempt at it.
 *
 * The distinction is the whole of this class. A package that cannot sync
 * cannot sync *hourly*: one expired credential across five hundred packages is
 * about ninety thousand exhausted jobs a day, and every one of them used to
 * write a row per admin, post to Slack and fan out to every subscribed
 * webhook. Slack answers a rate-limited post with an exception, so those
 * deliveries then retried and amplified the burst that caused them. The one
 * thing nobody learns from the five-hundredth notice is anything they did not
 * learn from the first.
 *
 * So what is announced is the *transition* into failure. The state is kept in
 * the cache rather than read back off `packages.sync_error`, because by the
 * time a job's failed() hook runs, every attempt before it has already written
 * that column — the previous state is gone, and a read-back would conclude
 * that nothing changed and never announce anything at all.
 *
 * Keyed per package and holding the reason, so a package that starts failing
 * for a *different* reason is announced again: "the credential expired" and
 * "the repository was deleted" are not the same incident, and the second must
 * not be swallowed because the first is still open.
 *
 * The entry expires rather than living forever, so a failure nobody has fixed
 * is raised again the next day. That is deliberate: a registry quietly not
 * syncing a package for a month is the failure this notification exists to
 * prevent, and one reminder a day is a very long way from one an hour.
 */
class SyncFailureNotifier
{
    /**
     * How long one unchanging failure stays quiet before it is raised again.
     */
    public const REMINDER_HOURS = 24;

    public function __construct(private readonly AdminNotifier $notifier) {}

    /**
     * Announce that this package's sync has given up, unless the same failure
     * has already been announced.
     */
    public function report(Package $package, string $reason): void
    {
        $key = $this->key($package);

        // The reason itself is not the value, because it is a provider error
        // that can carry a whole JSON body, and this store is the same one the
        // rendered payloads live in.
        $fingerprint = hash('xxh128', $reason);

        if (cache()->get($key) === $fingerprint) {
            return;
        }

        cache()->put($key, $fingerprint, now()->addHours(self::REMINDER_HOURS));

        $this->notifier->send(new PackageSyncFailed($package, $reason));
    }

    /**
     * Note that this package is syncing again, so that the next failure is
     * announced the moment it happens rather than waiting out the reminder
     * window of one that has since been fixed.
     */
    public function recovered(Package $package): void
    {
        cache()->forget($this->key($package));
    }

    private function key(Package $package): string
    {
        return 'package-sync-failure:'.$package->getKey();
    }
}
