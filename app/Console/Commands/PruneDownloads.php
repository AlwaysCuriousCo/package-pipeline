<?php

namespace App\Console\Commands;

use App\Models\Download;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only bound on the fastest-growing table in the schema.
 *
 * `downloads` gains a row for every zip served and nothing else ever deletes
 * one, so a registry that becomes worth measuring is a registry whose largest
 * table grows without limit — and it is the table both dashboard charts and
 * every export read.
 *
 * What makes this safe to run is the tally. `total_downloads` is a lifetime
 * figure and `downloads:recalculate` rebuilds it by counting these rows, so a
 * pruner that only deleted would have turned that recovery tool into a silent
 * demotion of every popular package's count. Counting what is about to go into
 * `pruned_downloads` first keeps the two commands telling the same story.
 */
class PruneDownloads extends Command
{
    protected $signature = 'downloads:prune
        {--days= : Override the configured retention window}
        {--dry-run : Report what would be deleted without touching anything}';

    protected $description = 'Delete download rows past the retention window, keeping their totals';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('registry.downloads.retention_days'));

        if ($days <= 0) {
            $this->components->info('Download history is kept indefinitely (retention is zero); nothing pruned.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $stale = Download::query()->where('created_at', '<', $cutoff);
        $count = $stale->clone()->count();

        if ($count === 0) {
            $this->components->info("No download rows older than {$days} days.");

            return self::SUCCESS;
        }

        if (! $dryRun) {
            // One transaction, because a crash between the tally and the
            // delete is the one outcome that must not happen: half of it
            // double-counts every pruned download, the other half loses it.
            DB::transaction(function () use ($cutoff): void {
                $this->tally('packages', 'package_id', $cutoff);
                $this->tally('package_versions', 'package_version_id', $cutoff);

                Download::query()->where('created_at', '<', $cutoff)->delete();
            });
        }

        $this->components->info(sprintf(
            '%s %d download row%s older than %d days; their totals are kept%s.',
            $dryRun ? 'Would prune' : 'Pruned',
            $count,
            $count === 1 ? '' : 's',
            $days,
            $dryRun ? ' (dry run)' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * Fold the rows about to be deleted into one table's `pruned_downloads`.
     *
     * A correlated subquery for the same reason `downloads:recalculate` uses
     * one — it is the single statement all three supported databases spell the
     * same way — but narrowed to the rows that actually have something to
     * count. Without that `where`, a nightly run that prunes one package's
     * history would still run a count per version in the registry.
     *
     * Raw SQL rather than Eloquent so that `updated_at` stands still: /p2 cuts
     * its ETag and Last-Modified from those columns, and a nightly sweep that
     * moved them would hand every client in the registry a fresh download of
     * metadata that had not changed.
     */
    private function tally(string $table, string $column, Carbon $cutoff): void
    {
        DB::statement(
            <<<SQL
                update {$table} set pruned_downloads = pruned_downloads + (
                    select count(*) from downloads
                    where downloads.{$column} = {$table}.id and downloads.created_at < ?
                )
                where id in (
                    select {$column} from downloads
                    where downloads.created_at < ? and downloads.{$column} is not null
                )
            SQL,
            [$cutoff, $cutoff],
        );
    }
}
