<?php

use App\Models\Notification;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Everything below is maintenance the app's own semantics already promise.
 * Releases arrive by webhook, so none of this is how a registry normally
 * learns about a push — it is the floor under the cases webhooks cannot
 * carry, plus the cleanup that keeps an installation from growing forever.
 *
 * Every task runs onOneServer(), because the README's deployment shape is
 * several app containers sharing an S3 dist disk and one database. That needs
 * a shared, lock-capable cache store (the default `database`, or `redis`);
 * with `file` or `array` each container holds its own lock and runs its own
 * copy of the sweep.
 */

// Three things quietly depend on a periodic sync. A package whose webhook
// coverage is Failed or None — which the registrar tolerates on purpose —
// never syncs otherwise; a version import that failed leaves behind a
// sync_error promising "the next sync will retry them", which is only true
// because of this line; and a repository that stops receiving pushes stops
// producing the deliveries that would have retried it.
//
// Hourly is affordable because an unchanged ref costs nothing: a version
// still pointing at the same sha is skipped by PackageSynchronizer::changed(),
// so a routine run is one tag listing and one branch listing per package and
// fans out no import jobs at all. --queue keeps it that way for the scheduler
// too — it dispatches one SyncPackageJob per package and returns, so the work
// runs on the queue under the uniqueness and overlap locks every other caller
// goes through, rather than serially inside the scheduler's process.
Schedule::command('packages:sync --queue')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Orphaned archives cost only disk, so this wants a quiet hour rather than a
// short interval; what matters is that it runs at all, since every re-synced
// version leaves its previous archive behind by design and nothing else ever
// deletes one.
Schedule::command('archives:clean')
    ->dailyAt('03:10')
    ->withoutOverlapping()
    ->onOneServer();

// The other half of the reconciliation above: a version row can outlive its
// archive, and nothing in the request path would ever notice — /p2 keeps
// advertising the version while dist answers 404 for it. The sync used to
// check by asking the disk about every stored version on every sync, which on
// an S3 dist disk is a HEAD request per version per package per hour; asked
// here it is one listing for the whole registry, against a failure that is
// rare and never urgent. Whatever it clears, the next hourly sync re-downloads.
Schedule::command('archives:audit')
    ->dailyAt('03:20')
    ->withoutOverlapping()
    ->onOneServer();

// AdminNotifier writes a row per admin per event and the panel's bell only
// ever marks them read, so the notifications table is otherwise append-only.
Schedule::command('model:prune', ['--model' => [Notification::class]])
    ->dailyAt('03:30')
    ->onOneServer();

// job_batches gains a row per sync and never loses one. Both readers — the
// panel's progress widget and SyncPackageJob's stale-batch check — only ever
// look at the batch a package points at right now, and Package::syncBatch()
// already answers null for one that has been pruned. Unfinished batches are
// kept longer than finished ones only so that a genuinely stuck sync is still
// inspectable the next morning.
Schedule::command('queue:prune-batches --hours=48 --unfinished=72 --cancelled=72')
    ->dailyAt('03:40')
    ->onOneServer();
