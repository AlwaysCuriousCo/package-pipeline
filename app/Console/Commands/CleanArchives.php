<?php

namespace App\Console\Commands;

use App\Models\PackageVersion;
use App\Services\ArchiveStore;
use Illuminate\Console\Command;

class CleanArchives extends Command
{
    protected $signature = 'archives:clean
        {--dry-run : List the files that would be deleted without touching them}';

    protected $description = 'Delete stored archives that no package version references';

    /**
     * Orphans accumulate by design: a re-stored version writes a new file and
     * leaves its old one, and bulk version deletes never fire per-row cleanup.
     * This command is where they go away.
     */
    public function handle(ArchiveStore $archives): int
    {
        $disk = $archives->disk();
        $dryRun = (bool) $this->option('dry-run');

        $referenced = PackageVersion::query()
            ->whereNotNull('archive_path')
            ->pluck('archive_path')
            ->flip();

        $orphans = array_values(array_filter(
            $disk->allFiles('packages'),
            fn (string $file): bool => ! $referenced->has($file),
        ));

        if ($orphans === []) {
            $this->components->info('Every stored archive is referenced by a version; nothing to clean.');

            return self::SUCCESS;
        }

        foreach ($orphans as $file) {
            if (! $dryRun) {
                $disk->delete($file);
            }

            $this->components->twoColumnDetail($file, $dryRun ? 'would delete' : 'deleted');
        }

        $this->components->info(sprintf(
            '%d orphaned archive%s %s.',
            count($orphans),
            count($orphans) === 1 ? '' : 's',
            $dryRun ? 'found; none deleted (dry run)' : 'deleted',
        ));

        return self::SUCCESS;
    }
}
