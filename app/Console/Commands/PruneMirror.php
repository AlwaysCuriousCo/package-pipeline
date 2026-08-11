<?php

namespace App\Console\Commands;

use App\Models\MirroredArchive;
use App\Models\MirroredPackage;
use App\Services\ArchiveStore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Number;
use Throwable;

class PruneMirror extends Command
{
    protected $signature = 'mirror:prune
        {--dry-run : Report what would be deleted without touching anything}';

    protected $description = 'Delete mirrored metadata and archives nothing has asked for lately';

    /**
     * How recently a mirrored file may have been written and still be spared.
     *
     * MirrorService writes the archive to the disk and then inserts the row
     * that claims it, so for that instant the file is indistinguishable from
     * an orphan. The same reasoning — and the same hour — as archives:clean:
     * comfortably longer than the archive timeout that bounds a download, and
     * wide enough to absorb the clock skew between this app and an object
     * store reporting a write time.
     */
    private const GRACE_MINUTES = 60;

    /**
     * The only bound on what the mirror costs in disk.
     *
     * Mirroring is on-demand caching with no ceiling of its own: every
     * transitive dependency of every project that resolves through this
     * registry is fetched once and kept. Left alone that grows monotonically
     * and forever, which is a slow way to fill a disk and take a registry
     * down — including for the private packages, which is the one thing this
     * app must never let happen.
     *
     * Retention is measured on last *use* rather than on age, so what goes is
     * exactly what nothing depends on any more: a package every build installs
     * is never evicted however long ago it was first cached, and one pulled in
     * by a single spike leaves after the window. Nothing is lost either way —
     * a pruned entry costs one upstream fetch the next time it is wanted.
     */
    public function handle(ArchiveStore $archives): int
    {
        $days = (int) config('registry.mirror.retention_days');
        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $documents = MirroredPackage::query()->where('used_at', '<', $cutoff);
        $documentCount = $documents->clone()->count();

        $stale = MirroredArchive::query()->where('used_at', '<', $cutoff)->get();

        $disk = $archives->disk();

        foreach ($stale as $archive) {
            if (! $dryRun) {
                $disk->delete((string) $archive->path);
            }

            $this->components->twoColumnDetail(
                "{$archive->name}@{$archive->reference}",
                Number::fileSize((int) $archive->size).($dryRun ? ' would be freed' : ' freed'),
            );
        }

        if (! $dryRun) {
            MirroredArchive::query()->whereKey($stale->modelKeys())->delete();
            $documents->delete();
        }

        $orphans = $this->orphans($disk, $dryRun);

        $this->components->info(sprintf(
            '%s %d cached document%s and %d archive%s (%s) unused for %d days%s.',
            $dryRun ? 'Would prune' : 'Pruned',
            $documentCount,
            $documentCount === 1 ? '' : 's',
            $stale->count(),
            $stale->count() === 1 ? '' : 's',
            Number::fileSize((int) $stale->sum('size')),
            $days,
            $dryRun ? ' (dry run)' : '',
        ));

        if ($orphans > 0) {
            $this->components->info(sprintf(
                '%d mirrored file%s on the disk that no row claims %s deleted.',
                $orphans,
                $orphans === 1 ? '' : 's',
                $dryRun ? 'would be' : 'were also',
            ));
        }

        return self::SUCCESS;
    }

    /**
     * Delete mirrored files nothing references, and say how many there were.
     *
     * These accumulate where the row and the file part company: a delete that
     * cascaded from a removed upstream takes the rows and leaves the objects,
     * and so does a crash between storing an archive and recording it. Only
     * the mirror prefix is listed — a published archive is archives:clean's,
     * and the two commands must never be able to see each other's files.
     */
    private function orphans(Filesystem $disk, bool $dryRun): int
    {
        $claimed = MirroredArchive::query()->pluck('path')->flip();

        $cutoff = now()->subMinutes(self::GRACE_MINUTES)->getTimestamp();

        $orphans = array_filter(
            $disk->allFiles(ArchiveStore::MIRROR_PREFIX),
            fn (string $file): bool => ! $claimed->has($file) && $this->settled($disk, $file, $cutoff),
        );

        if (! $dryRun) {
            foreach ($orphans as $file) {
                $disk->delete($file);
            }
        }

        return count($orphans);
    }

    /**
     * Whether the file was written long enough ago for the database to be the
     * authority on whether anything still references it. A disk that will not
     * say — or a file already gone since it was listed — has not proved the
     * file is garbage, and "I don't know" leaves it for the next run.
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
