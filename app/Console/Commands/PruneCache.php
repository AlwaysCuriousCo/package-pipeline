<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delete expired rows from the database cache store.
 *
 * Laravel's database store enforces a TTL only when the key is *read*: `get()`
 * notices the expiry, deletes the row and answers null. A key that is never
 * asked for again is therefore never noticed and never deleted, whatever its
 * TTL said — the expiry is a promise about the answer, not about the row.
 *
 * This app has that key shape everywhere, on purpose. The rendered `/p2`
 * payload is keyed by a validator cut from the version rows, so a sync
 * supersedes an entry rather than invalidating one and no write path has to
 * remember the cache exists; the mirror does the same, and the dashboard
 * widgets key on the day their window opens. Every one of those leaves behind
 * a key nothing will ever ask for again — one per package per sync, one per
 * viewer per day — and on the default `CACHE_STORE=database` that is a row
 * apiece, in a table with the metadata payloads in it.
 *
 * `cache_locks` is left alone: Illuminate\Cache\DatabaseLock already sweeps
 * expired locks on a 2-in-100 lottery whenever one is acquired, and this app
 * acquires them constantly.
 */
class PruneCache extends Command
{
    protected $signature = 'cache:prune';

    protected $description = 'Delete expired entries from the database cache store';

    public function handle(): int
    {
        $store = (string) config('cache.default');

        // Every other store expires on its own: redis and memcached evict on
        // the TTL they were given, the file store deletes on read like this one
        // but has no table to grow, and array lives and dies with the process.
        if (config("cache.stores.{$store}.driver") !== 'database') {
            $this->components->info("The `{$store}` cache store expires its own entries; nothing to prune.");

            return self::SUCCESS;
        }

        $table = (string) (config("cache.stores.{$store}.table") ?? 'cache');

        $pruned = DB::connection(config("cache.stores.{$store}.connection"))
            ->table($table)
            // Not `<`: an entry expiring exactly now is expired, which is the
            // comparison the store itself reads it with.
            ->where('expiration', '<=', now()->getTimestamp())
            ->delete();

        $this->components->info(sprintf(
            'Pruned %d expired cache %s.',
            $pruned,
            $pruned === 1 ? 'entry' : 'entries',
        ));

        return self::SUCCESS;
    }
}
