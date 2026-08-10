<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Sources\RepositoryClient;
use App\Support\VersionNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Rebuilds a package's stored versions from its repository.
 *
 * The work is cut into steps — discover, prune, import one ref, finalize — so
 * that the queued batch (app/Jobs/PackageSyncBatch.php) can run one ImportVersion
 * job per ref while sync() composes the same steps inline for the console
 * command and the tests. Either way there is exactly one implementation of
 * version building.
 */
class PackageSynchronizer
{
    public function __construct(
        private readonly ArchiveStore $archives,
        private readonly VersionNormalizer $normalizer = new VersionNormalizer,
    ) {}

    /**
     * Pull tags and branches from GitHub and rebuild the package's stored
     * versions, inline. The failure reason is recorded on the package before
     * the exception bubbles up to the caller.
     *
     * A ref that fails to import does not fail the sync: the others still
     * land, and finalize() notes the failures on the package. Only a sync
     * that produces no versions at all throws.
     */
    public function sync(Package $package, bool $force = false): SyncOutcome
    {
        try {
            $known = array_map(strval(...), $package->versions()->pluck('version')->all());

            $refs = $this->discover($package);

            $this->resolveComposerName($package);
            $this->prune($package, array_map(strval(...), array_keys($refs)));

            $changed = $this->changed($package, $refs, $force);

            $failed = 0;
            $lastError = null;

            foreach ($changed as $version => $ref) {
                try {
                    $this->import($package, (string) $version, $ref, $force);
                } catch (Throwable $exception) {
                    $failed++;
                    $lastError = $exception;
                }
            }

            // When nothing survived, the sync failed for whatever reason the
            // last ref did — "no versions" would bury the actual error.
            if ($lastError !== null && $package->versions()->count() === 0) {
                throw $lastError;
            }

            return $this->finalize($package, $known, count($changed), $failed);
        } catch (Throwable $exception) {
            $package->forceFill(['sync_error' => $exception->getMessage()])->save();

            throw $exception;
        }
    }

    /**
     * Every version the repository currently publishes, read from its tags and
     * branches, as [version => ['reference' => sha, 'is_dev' => bool]].
     *
     * @return array<string, array{reference: string, is_dev: bool}>
     */
    public function discover(Package $package): array
    {
        $client = $package->client();

        $versions = [];

        foreach ($client->tags() as $tag => $sha) {
            if (! $this->isVersionLikeTag($tag)) {
                continue;
            }

            // Stored under its normalized spelling (v1.2.3 → 1.2.3), so the
            // registry serves one vocabulary however maintainers tag.
            $versions[$this->normalizer->version($tag)] = ['reference' => $sha, 'is_dev' => false];
        }

        foreach ($client->branches() as $branch => $sha) {
            $versions[$this->normalizer->devVersion($branch)] = ['reference' => $sha, 'is_dev' => true];
        }

        return $versions;
    }

    /**
     * Give a never-synced package its composer name before any archive is
     * stored, so archive paths carry the real name rather than the placeholder
     * the create form started from. Read from the default branch because no
     * ref has been imported yet; after this, a name the repository changes is
     * reported rather than applied — see nameAfterSync().
     */
    public function resolveComposerName(Package $package): void
    {
        if ($package->last_synced_at !== null) {
            return;
        }

        $name = $package->client()->composerJson()['name'] ?? null;

        if (! is_string($name) || $name === '' || $name === $package->name) {
            return;
        }

        throw_if($this->nameTaken($package, $name), new \RuntimeException($this->nameConflict($name)));

        $package->forceFill(['name' => $name])->save();
    }

    /**
     * Delete stored versions the repository no longer publishes.
     *
     * Runs before the imports rather than after: the discovered ref list is
     * the authority on what exists upstream, and a version already gone should
     * not be served while the imports are still working through the batch.
     *
     * @param  list<string>  $versions  every version currently upstream
     */
    public function prune(Package $package, array $versions): void
    {
        $package->versions()->whereNotIn('version', $versions)->delete();
    }

    /**
     * The subset of discovered refs that actually need an import: new ones,
     * moved ones, and rows missing a piece a previous sync should have stored.
     *
     * @param  array<string, array{reference: string, is_dev: bool}>  $refs
     * @return array<string, array{reference: string, is_dev: bool}>
     */
    public function changed(Package $package, array $refs, bool $force = false): array
    {
        // A rebuild trusts nothing stored: every ref imports fresh, which is
        // the recovery hammer for corrupted archives and metadata drift.
        if ($force) {
            return $refs;
        }

        $known = $package->versions()->get()->keyBy('version');

        return array_filter(
            $refs,
            fn (array $ref, string|int $version): bool => ! $this->unchanged($known->get((string) $version), $ref),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Import one ref: read its composer.json and commit date, store the row,
     * and download and store its archive. Returns whether a version is now
     * stored — a ref without an installable composer.json is not an error,
     * but any row it used to have is removed rather than served stale.
     *
     * Safe to run twice: an already-imported, unmoved ref returns without
     * touching GitHub, which is what makes duplicate jobs from overlapping
     * syncs harmless.
     *
     * @param  array{reference: string, is_dev: bool}  $ref
     */
    public function import(Package $package, string $version, array $ref, bool $force = false): bool
    {
        $existing = $package->versions()->where('version', $version)->first();

        if (! $force && $this->unchanged($existing, $ref)) {
            return true;
        }

        $client = $package->client();

        $composerJson = $client->composerJson($ref['reference']);

        if (! isset($composerJson['name'])) {
            $existing?->delete();

            return false;
        }

        $data = [
            ...$ref,
            'order' => $this->normalizer->order($version),
            'released_at' => $client->commitDate($ref['reference']),
            'metadata' => [...$composerJson, 'version' => $version],
        ];

        $zip = $this->downloadArchive($client, $ref['reference']);

        try {
            // Row and archive columns land together, so a version is never
            // visible without the archive Composer will ask for.
            DB::transaction(function () use ($package, $version, $data, $zip): void {
                $model = $package->versions()->updateOrCreate(['version' => $version], $data);

                $this->archives->store($model->setRelation('package', $package), $zip);
            });
        } finally {
            File::delete($zip);
        }

        return true;
    }

    /**
     * Close out a sync: refresh the package's own columns from what is now
     * stored and report what changed against what was known before.
     *
     * @param  list<string>  $known  the versions the package had before the sync
     * @param  int  $attempted  how many refs the sync tried to import
     * @param  int  $failed  how many of those imports failed
     */
    public function finalize(Package $package, array $known, int $attempted = 0, int $failed = 0): SyncOutcome
    {
        $versions = $package->versions()->orderBy('id')->get();

        if ($versions->isEmpty()) {
            throw new \RuntimeException($failed > 0
                ? "All {$failed} version imports failed."
                : 'No installable versions found: no tag or branch contains a composer.json with a "name".');
        }

        $latest = $this->latestStableVersion(
            $versions->reject(fn (PackageVersion $v): bool => $v->is_dev)->pluck('version')->map(strval(...))->all(),
        );

        // The latest release describes the package; a repository without one
        // is described by whatever version arrived last.
        $newest = ($latest !== null ? $versions->firstWhere('version', $latest) : $versions->last())->metadata;

        $declared = $newest['name'] ?? null;

        [$name, $refused] = $this->nameAfterSync($package, is_string($declared) ? $declared : null);

        $package->forceFill([
            'name' => $name,
            'description' => $newest['description'] ?? $package->description,
            'type' => $newest['type'] ?? $package->type,
            'latest_version' => $latest,
            'last_synced_at' => now(),
            'sync_error' => $this->syncError($attempted, $failed, $refused),
        ])->save();

        return $this->outcome($known, $versions->pluck('is_dev', 'version')->all());
    }

    /**
     * The name to store after a sync, and the reason a rename was refused.
     *
     * A package's name is its identity here: it is what a consumer requires,
     * what every dist URL and archive path is built from, and what a lockfile
     * pins. So a composer.json that starts declaring a different one is not a
     * metadata update to be applied like `description` beside it. A tag
     * pointing at a fork, or one mistaken manifest, would otherwise move this
     * package's identity onto whatever the newest ref happens to claim — and a
     * registry silently reassigning who publishes a name is exactly the shape
     * of a dependency-confusion attack. The upload path already refuses the
     * same mismatch outright (CreateVersionFromZip::create), and a sync has
     * less reason to trust its input than an authenticated upload, not more.
     *
     * A rename is therefore only adopted before the package has ever been
     * synced, where it is not a rename at all but the first name this registry
     * has had for it. resolveComposerName() has usually settled that from the
     * default branch already, so reaching it here means the default branch
     * carried no readable composer.json and a ref did.
     *
     * Afterwards the stored name stands and the discrepancy is reported.
     * Upstream packages do genuinely get renamed, but only a human can tell
     * that from a hijack, and accepting it is then one edit in the panel —
     * after which the two agree and the notice stops. Refusing also keeps the
     * rename off the (repository_id, name) unique index, which finalize() used
     * to walk straight into: colliding with a name another package in the same
     * Composer repository publishes stored a raw SQL error as `sync_error`.
     *
     * @return array{string, ?string}
     */
    private function nameAfterSync(Package $package, ?string $declared): array
    {
        $current = (string) $package->name;

        if ($declared === null || $declared === '' || $declared === $current) {
            return [$current, null];
        }

        if ($package->last_synced_at === null) {
            throw_if($this->nameTaken($package, $declared), new \RuntimeException($this->nameConflict($declared)));

            return [$declared, null];
        }

        return [$current, "The repository's composer.json now names \"{$declared}\", but this package"
            ." publishes as \"{$current}\". The name was left alone: rename the package here to"
            .' accept it, or fix the composer.json.'];
    }

    /**
     * What the sync has to say for itself, or null when it has nothing.
     *
     * A partial sync is not a silent one — the versions that failed are simply
     * still missing — and neither is a rename this registry declined to make.
     */
    private function syncError(int $attempted, int $failed, ?string $refusedRename): ?string
    {
        $notes = array_filter([
            $failed > 0
                ? "{$failed} of {$attempted} version imports failed; the next sync will retry them."
                : null,
            $refusedRename,
        ]);

        return $notes === [] ? null : implode(' ', $notes);
    }

    /**
     * Whether another package in the same Composer repository already
     * publishes the given name.
     *
     * The (repository_id, name) unique index would reject the rename with a
     * bare query error; asked first, the conflict reads as what it is — one
     * Composer repository cannot serve two packages under one name.
     */
    private function nameTaken(Package $package, string $name): bool
    {
        return Package::query()
            ->where('repository_id', $package->repository_id)
            ->whereKeyNot($package->getKey())
            ->where('name', $name)
            ->exists();
    }

    private function nameConflict(string $name): string
    {
        return "The repository's composer.json is named \"{$name}\", which another package"
            .' in this Composer repository already publishes. Move one of them to a'
            .' different repository, or fix the composer.json name.';
    }

    /**
     * What changed, read off the versions that were known before the sync and
     * the ones it settled on.
     *
     * Tagged and dev versions are reported apart because they mean different
     * things to whoever is watching: a tag is a release, a branch moving is
     * Tuesday.
     *
     * @param  list<string>  $known  the versions the package had before
     * @param  array<string, bool>  $current  version => is_dev, as stored now
     */
    private function outcome(array $known, array $current): SyncOutcome
    {
        // A version like "1" is an integer once it is an array key, and every
        // comparison below reads better with the strings they started as.
        $isDev = array_combine(array_map(strval(...), array_keys($current)), array_values($current));
        $known = array_map(strval(...), $known);

        $added = array_diff(array_keys($isDev), $known);

        $releases = array_values(array_filter($added, fn (string $v): bool => ! $isDev[$v]));

        usort($releases, fn (string $a, string $b): int => version_compare(ltrim($a, 'vV'), ltrim($b, 'vV')));

        return new SyncOutcome(
            releases: $releases,
            devVersions: array_values(array_filter($added, fn (string $v): bool => $isDev[$v])),
            removed: array_values(array_diff($known, array_keys($isDev))),
            initialImport: $known === [],
            total: count($isDev),
        );
    }

    /**
     * Whether the stored row for a ref is complete and the ref has not moved,
     * meaning the import can be skipped entirely.
     *
     * A commit sha names an immutable tree, so a version still pointing at the
     * same sha cannot have a different composer.json or commit date than the
     * one already stored. Skipping it saves two API calls and an archive
     * download per version, which for a repository of any age is nearly the
     * whole sync. A row missing any piece — date, metadata, or archive — is
     * treated as changed so it is backfilled.
     *
     * The archive check asks the dist disk, not just the columns: a row can
     * outlive its file (storage loss, a deploy that wiped archives while the
     * database survived), and trusting the columns alone would skip the
     * re-download and leave dist serving 404s that no sync ever repairs.
     *
     * @param  array{reference: string, is_dev: bool}  $ref
     */
    private function unchanged(?PackageVersion $known, array $ref): bool
    {
        return $known instanceof PackageVersion
            && $known->reference === $ref['reference']
            && $known->is_dev === $ref['is_dev']
            && $known->released_at !== null
            && $known->archive_path !== null
            && $known->shasum !== null
            && isset($known->metadata['name'])
            && $this->archives->disk()->exists($known->archive_path);
    }

    /**
     * Download the zipball for a ref to a temporary file, returning its path.
     *
     * The caller owns the file's lifetime; import() deletes the download once
     * the archive is stored or abandoned.
     */
    private function downloadArchive(RepositoryClient $client, string $reference): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'package-archive-');

        throw_if($temporary === false, new \RuntimeException('Unable to create a temporary file for the archive.'));

        try {
            $client->downloadZipball($reference, $temporary);
        } catch (Throwable $exception) {
            File::delete($temporary);

            throw $exception;
        }

        return $temporary;
    }

    /**
     * Whether a tag name reads as a Composer version (1.2.3, v2.0.0-beta.1, ...).
     */
    private function isVersionLikeTag(string $tag): bool
    {
        return (bool) preg_match('/^v?\d+(\.\d+){0,3}([._-]?(alpha|beta|rc|dev|patch|pl|p)\.?\d*)?$/i', $tag);
    }

    /**
     * The highest non-prerelease tag, or null when only prereleases exist.
     *
     * @param  list<string>  $tags
     */
    private function latestStableVersion(array $tags): ?string
    {
        $stable = array_filter(
            $tags,
            fn (string $tag): bool => ! preg_match('/(alpha|beta|rc|dev)/i', $tag),
        );

        usort($stable, fn (string $a, string $b): int => version_compare(ltrim($a, 'vV'), ltrim($b, 'vV')));

        return $stable === [] ? null : end($stable);
    }
}
