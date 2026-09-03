<?php

namespace App\Console\Commands;

use App\Enums\Ecosystem;
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
     *
     * The match below is exact, which asks the dist disk to report a path back
     * as it was written. S3 and Linux do; a `local` disk on macOS or Windows,
     * and SMB or NFS mounted case-insensitively, do not — they keep whichever
     * casing a directory was first created under, so archives written after a
     * package name was lowercased are listed under the old spelling and every
     * one of them reads as an orphan. Folding the comparison would be worse
     * than the case it fixes: on a disk that can tell `Widgets.zip` from
     * `widgets.zip`, those are two objects and only one of them is referenced.
     * So the requirement is on the disk, and it is documented as one.
     *
     * @see docs/deployment.md#the-dist-disk-has-to-be-case-sensitive
     */
    public function handle(ArchiveStore $archives): int
    {
        $disk = $archives->disk();
        $dryRun = (bool) $this->option('dry-run');

        // A Python version holds several files — an sdist and its wheels —
        // and `archive_path` only ever names the most recently stored one;
        // the rest are referenced from the row's metadata and must not read
        // as orphans. @see CreatePypiFile
        $pypiFiles = PackageVersion::query()
            ->whereHas('package', fn ($query) => $query->where('ecosystem', Ecosystem::Pypi))
            ->pluck('metadata')
            ->flatMap(function (mixed $metadata): array {
                $decoded = is_string($metadata) ? json_decode($metadata, true) : $metadata;

                return array_values(array_filter(
                    array_column((array) (((array) $decoded)['files'] ?? []), 'path'),
                    is_string(...),
                ));
            });

        $referenced = PackageVersion::query()
            ->whereNotNull('archive_path')
            ->pluck('archive_path')
            ->merge($pypiFiles)
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
