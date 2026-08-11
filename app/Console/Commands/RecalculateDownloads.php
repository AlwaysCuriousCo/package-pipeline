<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the denormalized total_downloads counters from the downloads
 * table — the recovery tool for counters knocked out of step by imports,
 * manual surgery, or a listener that never ran.
 *
 * The rows are only half of what it rebuilds from. `downloads:prune` deletes
 * history past the retention window, and counts what it deleted into
 * `pruned_downloads` on its way — so the total is that tally plus whatever
 * rows are still there. Left out, this command would answer "downloads since
 * the retention window opened" and call it a lifetime figure, which is the one
 * way a repair tool can do more damage than the drift it was run to fix.
 *
 * @see PruneDownloads
 */
class RecalculateDownloads extends Command
{
    protected $signature = 'downloads:recalculate';

    protected $description = 'Rebuild the total_downloads counters from the downloads table';

    public function handle(): int
    {
        // Correlated subqueries rather than chunked model updates: one
        // statement per table, portable across SQLite, MySQL and Postgres.
        DB::statement(<<<'SQL'
            update packages set total_downloads = pruned_downloads + (
                select count(*) from downloads where downloads.package_id = packages.id
            )
        SQL);

        DB::statement(<<<'SQL'
            update package_versions set total_downloads = pruned_downloads + (
                select count(*) from downloads where downloads.package_version_id = package_versions.id
            )
        SQL);

        $this->components->info('Download counters rebuilt from the downloads table.');

        return self::SUCCESS;
    }
}
