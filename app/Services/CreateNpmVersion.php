<?php

namespace App\Services;

use App\Enums\Ecosystem;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Repository;
use App\Support\VersionNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publishes an npm version from what `npm publish` PUT — the manifest the
 * document carried and the tarball its attachment decoded to.
 *
 * The mirror of CreateVersionFromZip with the trust turned around: a zip is
 * opened and its composer.json read, where an npm publish carries the
 * manifest *beside* the tarball and the registry serves what it was handed.
 * That is the npm protocol's own shape — the public registry does the same —
 * and the tarball is still verifiable end to end, because the shasum and
 * integrity served with the manifest are computed here from the bytes rather
 * than copied from the client's claims.
 */
class CreateNpmVersion
{
    /**
     * Keys stripped from the manifest before it is stored. Underscore keys
     * are npm client bookkeeping (`_id`, `_nodeVersion`, `_npmUser` — which
     * would leak who published from where), the readme is megabytes of prose
     * the packument does not need, and `dist` is built fresh per mount at
     * serve time — a stored tarball URL would go stale the moment the
     * registry moved or the package was served from another repository.
     */
    private const DROPPED_KEYS = ['readme', 'readmeFilename', 'dist'];

    public function __construct(
        private readonly ArchiveStore $archives,
        private readonly VersionNormalizer $normalizer = new VersionNormalizer,
    ) {}

    /**
     * Create (or replace) the version the manifest describes, inside the
     * given repository, returning the stored row.
     *
     * @param  array<string, mixed>  $manifest  one entry of the publish document's `versions`
     * @param  string  $tarball  path to the decoded tarball on local disk
     */
    public function create(Repository $repository, string $name, array $manifest, string $tarball): PackageVersion
    {
        $version = trim((string) ($manifest['version'] ?? ''));

        if ($version === '') {
            throw ValidationException::withMessages([
                'versions' => 'The published manifest declares no version.',
            ]);
        }

        $package = $repository->packages()
            ->ofEcosystem(Ecosystem::Npm)
            ->where('packages.name', $name)
            ->first()
            ?? Package::query()->create([
                'repository_id' => $repository->id,
                'ecosystem' => Ecosystem::Npm,
                'name' => $name,
                'description' => $manifest['description'] ?? null,
                // No VCS repository backs a published npm package, so there
                // is nothing for a webhook to hear about — as with artifact
                // uploads.
                'webhook_enabled' => false,
            ]);

        $metadata = array_filter(
            array_diff_key($manifest, array_flip(self::DROPPED_KEYS)),
            fn (string $key): bool => ! str_starts_with($key, '_'),
            ARRAY_FILTER_USE_KEY,
        );

        $data = [
            // npm has no commit reference; the tarball's own hash stands in,
            // exactly as an artifact upload's does.
            'reference' => sha1_file($tarball),
            'order' => $this->normalizer->order($version),
            'is_dev' => false,
            'released_at' => now(),
            'metadata' => [
                ...$metadata,
                'name' => $name,
                'version' => $version,
                // Served inside `dist` alongside the sha1 `shasum` column.
                // Computed from the received bytes, never copied from the
                // client's document, so what npm verifies is what this
                // registry actually stored.
                '_integrity' => 'sha512-'.base64_encode((string) hash_file('sha512', $tarball, true)),
            ],
        ];

        $model = DB::transaction(function () use ($package, $version, $data, $tarball): PackageVersion {
            $model = $package->versions()->updateOrCreate(['version' => $version], $data);

            $this->archives->store($model->setRelation('package', $package), $tarball, 'tgz');

            return $model;
        });

        $package->refreshLatestVersion();

        if ($package->latest_version === $version || $package->wasRecentlyCreated) {
            $package->forceFill([
                'description' => $manifest['description'] ?? $package->description,
            ])->save();
        }

        return $model;
    }
}
