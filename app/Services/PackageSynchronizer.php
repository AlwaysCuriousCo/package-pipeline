<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Services\GitHub\GitHubClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class PackageSynchronizer
{
    public function __construct(private readonly ArchiveStore $archives) {}

    /**
     * Pull tags and branches from GitHub and rebuild the package's stored
     * versions. The failure reason is recorded on the package before the
     * exception bubbles up to the caller.
     */
    public function sync(Package $package): SyncOutcome
    {
        try {
            return $this->refresh($package, GitHubClient::for($package));
        } catch (Throwable $exception) {
            $package->forceFill(['sync_error' => $exception->getMessage()])->save();

            throw $exception;
        }
    }

    private function refresh(Package $package, GitHubClient $github): SyncOutcome
    {
        $versions = [];

        foreach ($github->tags() as $tag => $sha) {
            if (! $this->isVersionLikeTag($tag)) {
                continue;
            }

            $versions[$tag] = ['reference' => $sha, 'is_dev' => false];
        }

        foreach ($github->branches() as $branch => $sha) {
            $versions[$this->branchVersion($branch)] = ['reference' => $sha, 'is_dev' => true];
        }

        $known = $package->versions()->get()->keyBy('version');
        $synced = [];
        $downloads = [];

        try {
            foreach ($versions as $version => $ref) {
                $version = (string) $version;

                if (($unchanged = $this->unchanged($known->get($version), $ref)) !== null) {
                    $synced[$version] = $unchanged;

                    continue;
                }

                $composerJson = $github->composerJson($ref['reference']);

                // A ref without a composer.json (or without a package name) is not
                // installable, so it is skipped rather than treated as an error.
                if (! isset($composerJson['name'])) {
                    continue;
                }

                $synced[$version] = [
                    ...$ref,
                    'released_at' => $github->commitDate($ref['reference']),
                    'metadata' => [...$composerJson, 'version' => $version],
                ];

                $downloads[$version] = $this->downloadArchive($github, $ref['reference']);
            }

            if ($synced === []) {
                throw new \RuntimeException('No installable versions found: no tag or branch contains a composer.json with a "name".');
            }

            // The most recently built ref wins; every synced ref of one repository
            // should agree on the package name anyway.
            $composerName = end($synced)['metadata']['name'];
            $latest = $this->latestStableVersion(array_keys(array_filter($synced, fn (array $v): bool => ! $v['is_dev'])));
            $newest = $synced[$latest ?? array_key_last($synced)]['metadata'];

            DB::transaction(function () use ($package, $synced, $downloads, $composerName, $latest, $newest): void {
                // The package is renamed before archives are stored, so their
                // paths carry the composer name rather than the placeholder a
                // first sync starts from.
                $package->forceFill([
                    'name' => $composerName,
                    'description' => $newest['description'] ?? $package->description,
                    'type' => $newest['type'] ?? $package->type,
                    'latest_version' => $latest,
                    'last_synced_at' => now(),
                    'sync_error' => null,
                ])->save();

                $package->versions()->whereNotIn('version', array_keys($synced))->delete();

                foreach ($synced as $version => $data) {
                    $model = $package->versions()->updateOrCreate(['version' => (string) $version], $data);

                    if (isset($downloads[$version])) {
                        $this->archives->store($model->setRelation('package', $package), $downloads[$version]);
                    }
                }
            });
        } finally {
            File::delete(array_values($downloads));
        }

        return $this->outcome($known->keys()->all(), $synced);
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
     * @param  array<string, array{is_dev: bool, ...}>  $synced
     */
    private function outcome(array $known, array $synced): SyncOutcome
    {
        // A version like "1" is an integer once it is an array key, and every
        // comparison below reads better with the strings they started as.
        $current = array_map(strval(...), array_keys($synced));
        $known = array_map(strval(...), $known);

        $added = array_diff($current, $known);

        $releases = array_values(array_filter($added, fn (string $v): bool => ! $synced[$v]['is_dev']));

        usort($releases, fn (string $a, string $b): int => version_compare(ltrim($a, 'vV'), ltrim($b, 'vV')));

        return new SyncOutcome(
            releases: $releases,
            devVersions: array_values(array_filter($added, fn (string $v): bool => $synced[$v]['is_dev'])),
            removed: array_values(array_diff($known, $current)),
            initialImport: $known === [],
            total: count($synced),
        );
    }

    /**
     * The stored row for a ref that has not moved since the last sync, in the
     * shape the sync writes back — or null when the ref has to be re-read.
     *
     * A commit sha names an immutable tree, so a version still pointing at the
     * same sha cannot have a different composer.json or commit date than the
     * one already stored. Reusing it saves two API calls and an archive
     * download per version, which for a repository of any age is nearly the
     * whole sync. A row missing any piece — date, metadata, or archive — is
     * treated as changed so it is backfilled.
     *
     * @param  array{reference: string, is_dev: bool}  $ref
     * @return array{reference: string, is_dev: bool, released_at: CarbonImmutable, metadata: array<string, mixed>}|null
     */
    private function unchanged(?PackageVersion $known, array $ref): ?array
    {
        if (! $known instanceof PackageVersion
            || $known->reference !== $ref['reference']
            || $known->is_dev !== $ref['is_dev']
            || $known->released_at === null
            || $known->archive_path === null
            || $known->shasum === null
            || ! isset($known->metadata['name'])) {
            return null;
        }

        return [
            ...$ref,
            'released_at' => $known->released_at,
            'metadata' => $known->metadata,
        ];
    }

    /**
     * Download the zipball for a ref to a temporary file, returning its path.
     *
     * The caller owns the file's lifetime; refresh() deletes every download
     * once the sync has stored or abandoned them.
     */
    private function downloadArchive(GitHubClient $github, string $reference): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'package-archive-');

        throw_if($temporary === false, new \RuntimeException('Unable to create a temporary file for the archive.'));

        try {
            $github->downloadZipball($reference, $temporary);
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
     * The Composer dev version for a branch, following Packagist's naming:
     * numeric branches become "2.0.x-dev" / "1.x-dev", others "dev-main".
     */
    private function branchVersion(string $branch): string
    {
        if (preg_match('/^v?\d+(\.\d+)*$/', $branch)) {
            return "{$branch}.x-dev";
        }

        if (preg_match('/^v?\d+(\.(\d+|x))*$/i', $branch)) {
            return "{$branch}-dev";
        }

        return "dev-{$branch}";
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
