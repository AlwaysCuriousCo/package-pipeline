<?php

namespace App\Services;

use App\Models\Package;
use App\Services\GitHub\GitHubClient;
use Illuminate\Support\Facades\DB;
use Throwable;

class PackageSynchronizer
{
    /**
     * Pull tags and branches from GitHub and rebuild the package's stored
     * versions. The failure reason is recorded on the package before the
     * exception bubbles up to the caller.
     */
    public function sync(Package $package): void
    {
        try {
            $this->refresh($package, GitHubClient::for($package));
        } catch (Throwable $exception) {
            $package->forceFill(['sync_error' => $exception->getMessage()])->save();

            throw $exception;
        }
    }

    private function refresh(Package $package, GitHubClient $github): void
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

        $synced = [];

        foreach ($versions as $version => $ref) {
            $composerJson = $github->composerJson($ref['reference']);

            // A ref without a composer.json (or without a package name) is not
            // installable, so it is skipped rather than treated as an error.
            if (! isset($composerJson['name'])) {
                continue;
            }

            $synced[$version] = [
                ...$ref,
                'metadata' => [...$composerJson, 'version' => (string) $version],
            ];
        }

        if ($synced === []) {
            throw new \RuntimeException('No installable versions found: no tag or branch contains a composer.json with a "name".');
        }

        // The most recently built ref wins; every synced ref of one repository
        // should agree on the package name anyway.
        $composerName = end($synced)['metadata']['name'];
        $latest = $this->latestStableVersion(array_keys(array_filter($synced, fn (array $v): bool => ! $v['is_dev'])));
        $newest = $synced[$latest ?? array_key_last($synced)]['metadata'];

        DB::transaction(function () use ($package, $synced, $composerName, $latest, $newest): void {
            $package->versions()->whereNotIn('version', array_keys($synced))->delete();

            foreach ($synced as $version => $data) {
                $package->versions()->updateOrCreate(['version' => (string) $version], $data);
            }

            $package->forceFill([
                'name' => $composerName,
                'description' => $newest['description'] ?? $package->description,
                'type' => $newest['type'] ?? $package->type,
                'latest_version' => $latest,
                'last_synced_at' => now(),
                'sync_error' => null,
            ])->save();
        });
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
