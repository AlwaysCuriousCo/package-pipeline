<?php

namespace App\Console\Commands;

use App\Models\PackageVersion;
use App\Services\ArchiveStore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Throwable;

class CleanArchives extends Command
{
    protected $signature = 'archives:clean
        {--dry-run : List the files that would be deleted without touching them}';

    protected $description = 'Delete stored archives that no package version references';

    /**
     * How recently a file may have been written and still be left alone.
     *
     * PackageSynchronizer::import() writes the archive to the disk *inside*
     * the transaction that saves the row pointing at it, so for the length of
     * that transaction the file is indistinguishable from an orphan: present
     * on the disk, referenced by nothing this command can read. Deleting it
     * there commits a version whose archive is already gone, and dist answers
     * 404 for it until a later sync notices the missing file. Nothing written
     * this recently can be garbage, so leave it: an hour is comfortably longer
     * than an import's own 300-second timeout, and it also absorbs the clock
     * skew between this app and an object store reporting the write time.
     */
    private const GRACE_MINUTES = 60;

    /**
     * Orphans accumulate by design: a re-stored version writes a new file and
     * leaves its old one, and bulk version deletes never fire per-row cleanup.
     * This command is where they go away.
     *
     * Only the published prefix is listed, and that is load-bearing rather
     * than incidental: mirrored upstream archives sit on the same disk and are
     * referenced by `mirrored_archives`, a table this command knows nothing
     * about. Listing the whole disk would make every one of them look like an
     * orphan and delete the mirror cache. `mirror:prune` is their sweep.
     */
    public function handle(ArchiveStore $archives): int
    {
        $disk = $archives->disk();
        $dryRun = (bool) $this->option('dry-run');

        $referenced = PackageVersion::query()
            ->whereNotNull('archive_path')
            ->pluck('archive_path')
            ->flip();

        $unreferenced = array_filter(
            $disk->allFiles(ArchiveStore::PUBLISHED_PREFIX),
            fn (string $file): bool => ! $referenced->has($file),
        );

        $cutoff = now()->subMinutes(self::GRACE_MINUTES)->getTimestamp();

        $orphans = array_values(array_filter(
            $unreferenced,
            fn (string $file): bool => $this->settled($disk, $file, $cutoff),
        ));

        $recent = count($unreferenced) - count($orphans);

        if ($orphans === []) {
            $this->components->info($recent === 0
                ? 'Every stored archive is referenced by a version; nothing to clean.'
                : 'Nothing to clean: every unreferenced file is too recently written to judge.');

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

        if ($recent > 0) {
            $this->components->info(sprintf(
                '%d unreferenced file%s written in the last %d minutes left alone; an import still committing looks just like an orphan.',
                $recent,
                $recent === 1 ? '' : 's',
                self::GRACE_MINUTES,
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Whether the file was written long enough ago for the database to be the
     * authority on whether anything still references it.
     *
     * lastModified() is on the Filesystem contract, so local disks and S3 both
     * answer it. A disk that refuses to — or a file already deleted since it
     * was listed — cannot prove the file is garbage, and the safe reading of
     * "I don't know" is to leave it for the next run.
     */
    private function settled(Filesystem $disk, string $file, int $cutoff): bool
    {
        try {
            return $disk->lastModified($file) < $cutoff;
        } catch (Throwable) {
            return false;
        }
    }
}
