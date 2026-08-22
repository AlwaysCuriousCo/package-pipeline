<?php

use App\Models\Activity;
use App\Models\MerchantEvent;
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
 *
 * Every withoutOverlapping() below names its own expiry, because the default is
 * 24 hours and that is a number for a task nobody meant to size. Laravel
 * releases the mutex on SIGTERM and SIGINT, which covers an orderly deploy —
 * and covers nothing about a SIGKILL, an OOM kill or a node that goes away, all
 * of which leave the lock held with no process behind it. At the default that
 * silently skips a whole day of hourly syncs; at these it costs one run.
 *
 * The numbers are the same shape everywhere: comfortably longer than the work
 * can honestly take, and comfortably shorter than the interval, so a lock left
 * behind by a killed run has expired before anybody notices.
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
    // It dispatches and returns, so fifteen minutes is already generous for a
    // registry with thousands of packages, and a crashed run costs the next
    // hour rather than the rest of the day.
    ->withoutOverlapping(15)
    ->onOneServer();

// Orphaned archives cost only disk, so this wants a quiet hour rather than a
// short interval; what matters is that it runs at all, since every re-synced
// version leaves its previous archive behind by design and nothing else ever
// deletes one.
Schedule::command('archives:clean')
    ->dailyAt('03:10')
    // Four hours: the sweep lists a whole dist bucket and deletes from it, and
    // an S3 listing is paginated over however many objects the registry has.
    ->withoutOverlapping(240)
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
    ->withoutOverlapping(240)
    ->onOneServer();

// The only bound on what upstream mirroring costs in disk. Every transitive
// dependency of every project that resolves through a mirroring repository is
// fetched once and kept, so without this the dist disk grows monotonically and
// forever — and a full disk takes the private packages down with the cached
// ones. A no-op on an installation with no upstreams, which is every
// installation until an operator adds one.
Schedule::command('mirror:prune')
    ->dailyAt('03:15')
    ->withoutOverlapping(240)
    ->onOneServer();

// AdminNotifier writes a row per admin per event and the panel's bell only
// ever marks them read, so the notifications table is otherwise append-only.
// The audit log is append-only by design — nothing in the app updates or
// deletes an entry — so it needs the same treatment, with a much longer
// retention: the questions it answers are asked months after the change.
Schedule::command('model:prune', ['--model' => [Notification::class, Activity::class, MerchantEvent::class]])
    ->dailyAt('03:30')
    ->onOneServer();

// The safety net under the billing webhooks, and the clock for everything
// local: re-pulls merchant-billed subscriptions, crosses the boundaries
// nobody webhooks about (grace running out, a Manual period ending, an
// updates window closing), and sends the trial-ending warnings. A no-op in
// one query while billing is disabled.
Schedule::command('billing:reconcile')
    ->dailyAt('04:00')
    ->withoutOverlapping(60)
    ->onOneServer();

// The fastest-growing table in the schema, and the last one to get a bound:
// a row per zip served, read by both download charts and every export, and
// deleted by nothing. What this prunes is the detail — which credential
// fetched which version on which day — and not the totals: it folds the rows
// it removes into `pruned_downloads` first, so the counters and
// `downloads:recalculate` still answer with a lifetime figure afterwards.
Schedule::command('downloads:prune')
    ->dailyAt('03:50')
    // A transaction over one table, but the first run after a registry has been
    // serving for years has a lot of rows to get through.
    ->withoutOverlapping(120)
    ->onOneServer();

// The database cache store expires an entry when the key is read and at no
// other time, so a key nothing asks for again is a row that outlives its TTL
// forever. This app supersedes entries rather than invalidating them — the /p2
// payload is keyed by its own validator, the mirror's likewise, the widgets by
// the day their window opens — which is what makes that shape the normal case
// here rather than an oddity. A no-op on redis.
Schedule::command('cache:prune')
    ->dailyAt('03:45')
    // One delete statement.
    ->withoutOverlapping(30)
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
