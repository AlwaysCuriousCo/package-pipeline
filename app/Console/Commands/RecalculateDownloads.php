<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the denormalized total_downloads counters from the downloads
 * table — the recovery tool for counters knocked out of step by imports,
 * manual surgery, or a listener that never ran.
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
            update packages set total_downloads = (
                select count(*) from downloads where downloads.package_id = packages.id
            )
        SQL);

        DB::statement(<<<'SQL'
            update package_versions set total_downloads = (
                select count(*) from downloads where downloads.package_version_id = package_versions.id
            )
        SQL);

        $this->components->info('Download counters rebuilt from the downloads table.');

        return self::SUCCESS;
    }
}
