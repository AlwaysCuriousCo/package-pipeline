<?php

namespace App\Console\Commands;

use App\Models\PackageVersion;
use App\Services\ArchiveStore;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class AuditArchives extends Command
{
    protected $signature = 'archives:audit
        {--dry-run : Report the versions whose archive is missing without touching them}
        {--force : Clear the rows even when the scale of the loss reads as a disk that is wrong rather than lost}';

    protected $description = 'Find versions whose stored archive is no longer on the dist disk';

    /**
     * How recently a version may have been written and still be believed.
     *
     * PackageSynchronizer::import() writes the archive inside the transaction
     * that saves the row pointing at it, so between the listing below and the
     * query after it a version can be committed whose file this run never saw.
     * Nothing touched this recently is evidence of anything; an hour is
     * comfortably longer than an import's own 300-second timeout and absorbs
     * the clock skew between this app and an object store.
     */
    private const GRACE_MINUTES = 60;

    /**
     * The share of the checked versions that may look lost before this run
     * treats the disk, rather than the registry, as the thing that is wrong.
     *
     * The two explanations are not close together. A referenced archive is
     * only ever written by an import and only ever deleted by archives:clean,
     * which deletes nothing a row still points at — so genuine loss arrives
     * one object at a time: a bad delete, a failed multipart upload, a
     * lifecycle rule somebody wrote. Every way of losing the *premise*, on the
     * other hand, loses the whole prefix at once: DIST_DISK repointed at a new
     * bucket, a changed path prefix, credentials that now open a different
     * account, a container holding only its own slice of a local disk, a
     * bucket restored from a snapshot taken before most of these versions
     * existed. Those land at or near 100%.
     *
     * Ten percent sits in the empty middle. It is far above any plausible
     * trickle of real loss and far below every bulk mode, so it is not really
     * a tuning knob: anything from a few percent to a half would refuse the
     * same runs. Low is the cheaper mistake, because refusing is one console
     * line and clearing is unattended at 03:20.
     */
    private const BULK_LOSS_FRACTION = 0.10;

    /**
     * How many versions must look lost before the share above is consulted at
     * all.
     *
     * A share is meaningless on a small registry: twelve versions of which
     * three are genuinely gone is 25%, and left to the fraction alone such a
     * registry could never repair itself unattended. Below this many rows
     * there is no bulk event to catch — a real misconfiguration on a registry
     * this size costs a human one `--force` — so the count decides and the
     * share does not.
     */
    private const BULK_LOSS_FLOOR = 20;

    /**
     * Reconcile the versions against the disk they are served from.
     *
     * A row can outlive its file — storage loss, a deploy that wiped archives
     * while the database survived, a bucket restored from an older snapshot —
     * and nothing else would ever notice: `/p2` keeps advertising the version
     * and dist answers 404 for it.
     *
     * The sync used to catch this by asking the disk whether each stored
     * archive existed, on every sync of every package. On an S3-backed dist
     * disk that is one HEAD request per version per sync — for a package with
     * two hundred versions, two hundred round trips to conclude that nothing
     * changed, now hourly for every package. The same question is answered
     * here for the whole registry with one listing, on a schedule that suits
     * how rarely it is ever true.
     *
     * Repair is left to the sync rather than done here: clearing the columns
     * is enough to make the version look unfinished, and PackageSynchronizer
     * already knows how to re-download and re-store one. So this command needs
     * no provider credentials, no downloads, and no idea of what a sync is.
     * That is also the whole reason the guards below exist — clearing is only
     * cheap where the repair is real, and this command is deliberately unable
     * to tell whether it is.
     *
     * The listing is matched against `archive_path` exactly, so a dist disk
     * that does not report a path back as it was written reads as loss. Only
     * case-insensitive ones do that — a `local` disk on macOS or Windows, SMB
     * or NFS — and after package names were lowercased they report the archives
     * of a renamed package under the directory's original casing. The
     * proportional guard below catches it as "the disk is wrong", which is very
     * nearly the right diagnosis; the requirement itself is documented.
     *
     * @see docs/deployment.md#the-dist-disk-has-to-be-case-sensitive
     */
    public function handle(ArchiveStore $archives): int
    {
        $disk = $archives->disk();

        $cutoff = now()->subMinutes(self::GRACE_MINUTES);

        // Listed before the rows are read, so that anything committed in
        // between is newer than the cutoff and left for the next run.
        //
        // The published prefix only: mirrored archives share the disk and
        // answer to `mirrored_archives`, so counting them here would compare
        // one table against two tables' worth of files.
        $stored = array_flip($disk->allFiles(ArchiveStore::PUBLISHED_PREFIX));

        $referenced = PackageVersion::query()->whereNotNull('archive_path')->count();

        // A disk that lists nothing while the database references archives is
        // far more likely to be misconfigured or unreachable than a registry
        // that lost every file at once — and acting on it would clear every
        // archive the registry has. Asked ahead of the proportional guard
        // below because it is the one shape that guard cannot see: a registry
        // of a handful of versions pointed at an empty bucket is under the
        // floor, and 100% of nothing checked is not a fraction at all.
        if ($stored === [] && $referenced > 0 && ! $this->force()) {
            $this->components->error(
                "The dist disk lists no files at all, but {$referenced} versions reference an archive on it. "
                .'Refusing to treat that as archive loss; check the disk configuration, '
                .'or pass --force if every archive really is gone.'
            );

            return self::FAILURE;
        }

        $checked = PackageVersion::query()
            ->with('package:id,name,repository')
            ->whereNotNull('archive_path')
            ->where('updated_at', '<', $cutoff)
            ->get(['id', 'package_id', 'version', 'archive_path']);

        $lost = $checked->filter(fn (PackageVersion $version): bool => ! isset($stored[$version->archive_path]));

        if ($lost->isEmpty()) {
            $this->components->info("Every stored archive is where its version says it is ({$referenced} checked).");

            return self::SUCCESS;
        }

        if ($this->looksLikeTheDisk($lost->count(), $checked->count())) {
            return self::FAILURE;
        }

        // A package with no repository URL was published by artifact upload
        // and cannot be synced at all — packages:sync refuses it outright,
        // because there is nowhere to sync it from. Clearing its columns is
        // therefore not "make the version look unfinished so the next sync
        // redoes it"; it is deleting the only record of which object on the
        // disk that version was and what its bytes hashed to, which is exactly
        // what an operator needs to restore it from a backup. The row is worth
        // more broken than erased, so it is reported and left.
        [$repairable, $unrepairable] = $lost->partition(
            fn (PackageVersion $version): bool => filled($version->package?->repository),
        );

        $this->report($repairable, $unrepairable);

        if (! $this->option('dry-run') && $repairable->isNotEmpty()) {
            // Through the base builder so `updated_at` is left where it is.
            // That column is half of what /p2 cuts its ETag and Last-Modified
            // from, so stamping it here would invalidate every affected
            // package's metadata for every Composer client in the estate — to
            // hand them a document that still advertises the same versions and
            // still points at a dist that still 404s, minus a `shasum` key.
            // The repair is what changes something worth refetching, and the
            // import that performs it moves the timestamp itself.
            PackageVersion::query()->whereKey($repairable->modelKeys())->toBase()->update([
                'archive_path' => null,
                'shasum' => null,
            ]);
        }

        $this->summarize($repairable->count(), $unrepairable->count());

        return self::SUCCESS;
    }

    /**
     * Whether this much loss is better explained by the disk being the wrong
     * disk than by the registry having lost that many archives — and say so if
     * it is.
     */
    private function looksLikeTheDisk(int $lost, int $checked): bool
    {
        if ($this->force() || $lost < self::BULK_LOSS_FLOOR) {
            return false;
        }

        // Against what was actually checked rather than everything referenced.
        // A version inside the grace window was deliberately not judged, and
        // counting it as present would let a burst of fresh imports dilute the
        // very signal this is reading.
        if ($lost / max($checked, 1) <= self::BULK_LOSS_FRACTION) {
            return false;
        }

        $this->components->error(sprintf(
            '%d of the %d versions checked (%d%%) have no archive on the dist disk. '
            .'At that scale the disk is far more likely to be the wrong one — a repointed DIST_DISK, '
            .'a changed path prefix, credentials for another account, or a bucket restored from an older '
            .'snapshot — than the registry to have lost that many archives, so nothing was cleared. '
            .'Check the disk, run with --dry-run to see the list, and pass --force once you are sure.',
            $lost,
            $checked,
            (int) round($lost / max($checked, 1) * 100),
        ));

        return true;
    }

    /**
     * Whether the operator has taken responsibility for the scale of this.
     */
    private function force(): bool
    {
        // A dry run changes nothing, so the guards have nothing to protect
        // against and refusing would only hide the list the operator asked
        // for — which is the list they need to decide whether to force.
        return (bool) $this->option('force') || (bool) $this->option('dry-run');
    }

    /**
     * @param  Collection<int, PackageVersion>  $repairable
     * @param  Collection<int, PackageVersion>  $unrepairable
     */
    private function report(Collection $repairable, Collection $unrepairable): void
    {
        foreach ($repairable as $version) {
            $this->components->twoColumnDetail(
                "{$version->package?->name} {$version->version}",
                (string) $version->archive_path,
            );
        }

        foreach ($unrepairable as $version) {
            $this->components->warn(sprintf(
                '%s %s is missing %s and was published by artifact upload, so no sync can rebuild it. '
                .'The row was left alone: it is the only record of which object held those bytes. '
                .'Restore the file from a backup, or delete the version.',
                $version->package?->name,
                $version->version,
                $version->archive_path,
            ));
        }
    }

    private function summarize(int $cleared, int $kept): void
    {
        if ($cleared > 0) {
            $this->components->info(sprintf(
                '%d version%s missing %s archive%s; %s',
                $cleared,
                $cleared === 1 ? '' : 's',
                $cleared === 1 ? 'its' : 'their',
                $cleared === 1 ? '' : 's',
                $this->option('dry-run')
                    ? 'nothing was changed (dry run).'
                    : 'cleared, so the next sync downloads them again.',
            ));
        }

        if ($kept > 0) {
            $this->components->info(sprintf(
                '%d uploaded-artifact version%s left untouched; %s needs a restore or a delete by hand.',
                $kept,
                $kept === 1 ? '' : 's',
                $kept === 1 ? 'it' : 'each',
            ));
        }
    }
}
