<?php

namespace App\Console\Commands;

use App\Models\PackageVersion;
use App\Services\ArchiveStore;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class AuditArchives extends Command
{
    protected $signature = 'archives:audit
        {--dry-run : Report the versions whose archive is missing without touching them}';

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
        // archive the registry has.
        if ($stored === [] && $referenced > 0) {
            $this->components->error(
                "The dist disk lists no files at all, but {$referenced} versions reference an archive on it. "
                .'Refusing to treat that as archive loss; check the disk configuration.'
            );

            return self::FAILURE;
        }

        $lost = PackageVersion::query()
            ->with('package:id,name')
            ->whereNotNull('archive_path')
            ->where('updated_at', '<', $cutoff)
            ->get(['id', 'package_id', 'version', 'archive_path'])
            ->filter(fn (PackageVersion $version): bool => ! isset($stored[$version->archive_path]));

        if ($lost->isEmpty()) {
            $this->components->info("Every stored archive is where its version says it is ({$referenced} checked).");

            return self::SUCCESS;
        }

        $this->report($lost);

        if (! $this->option('dry-run')) {
            PackageVersion::query()->whereKey($lost->modelKeys())->update([
                'archive_path' => null,
                'shasum' => null,
            ]);
        }

        $this->components->info(sprintf(
            '%d version%s missing %s archive%s; %s',
            $lost->count(),
            $lost->count() === 1 ? '' : 's',
            $lost->count() === 1 ? 'its' : 'their',
            $lost->count() === 1 ? '' : 's',
            $this->option('dry-run')
                ? 'nothing was changed (dry run).'
                : 'cleared, so the next sync downloads them again.',
        ));

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, PackageVersion>  $lost
     */
    private function report(Collection $lost): void
    {
        foreach ($lost as $version) {
            $this->components->twoColumnDetail(
                "{$version->package?->name} {$version->version}",
                (string) $version->archive_path,
            );
        }
    }
}
