<?php

namespace App\Services;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Repository;
use App\Support\VersionNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Publishes a version from an uploaded zip — the "build artifact → publish"
 * path, where CI pushes what it built instead of the registry pulling from a
 * VCS provider.
 *
 * The zip is the source of truth: its composer.json provides the metadata and
 * the file itself becomes the served archive, so what Composer reads and what
 * it downloads can never disagree.
 */
class CreateVersionFromZip
{
    /**
     * The composer.json keys worth serving. Everything Composer needs to
     * resolve and install, nothing that leaks build internals — and a bound
     * on `/p2` payload size whatever a project stuffs into its manifest.
     */
    private const METADATA_KEYS = [
        'description', 'type', 'license', 'authors', 'keywords', 'homepage',
        'support', 'bin', 'autoload', 'autoload-dev', 'require', 'require-dev',
        'conflict', 'provide', 'replace', 'suggest', 'minimum-stability',
        'prefer-stable', 'scripts', 'extra',
    ];

    /**
     * The most a composer.json may weigh, uncompressed.
     *
     * Real manifests are kilobytes — the fattest on Packagist, with hundreds of
     * requirements and a wall of `extra`, stay well under 100 KB — so a
     * megabyte is generous by an order of magnitude while still being a bound
     * an archive cannot talk its way past.
     */
    private const MAX_MANIFEST_BYTES = 1024 * 1024;

    public function __construct(
        private readonly ArchiveStore $archives,
        private readonly VersionNormalizer $normalizer = new VersionNormalizer,
    ) {}

    /**
     * Create (or replace) the version the zip describes, inside the given
     * repository, returning the stored row.
     *
     * @param  string  $name  the "vendor/package" the upload was addressed to
     * @param  string  $zip  path to the uploaded zip on local disk
     */
    public function create(Repository $repository, string $name, string $zip, ?string $version = null): PackageVersion
    {
        $composerJson = $this->composerJson($zip);

        // The URL names the package; a manifest naming something else is a
        // publish to the wrong address, not a detail to reconcile silently.
        if (isset($composerJson['name']) && strcasecmp((string) $composerJson['name'], $name) !== 0) {
            throw ValidationException::withMessages([
                'file' => "The zip's composer.json names \"{$composerJson['name']}\", but the upload is addressed to \"{$name}\".",
            ]);
        }

        $version ??= $composerJson['version'] ?? null;

        if (blank($version)) {
            throw ValidationException::withMessages([
                'version' => 'No version was given and the composer.json declares none.',
            ]);
        }

        $version = (string) $version;

        // Whatever this repository already serves under the name, which is not
        // necessarily a package that lives here: one added from another
        // repository answers under this mount, and an upload addressed to that
        // name is a version of it rather than a second package with the same
        // name — which the serving pivot's unique index would refuse anyway.
        $package = $repository->packages()->where('packages.name', $name)->first()
            ?? Package::query()->create([
                'repository_id' => $repository->id,
                'name' => $name,
                'description' => $composerJson['description'] ?? null,
                'type' => $composerJson['type'] ?? null,
                // There is no VCS repository behind an uploaded package, so
                // there is nothing for a webhook to hear about.
                'webhook_enabled' => false,
            ]);

        $data = [
            // Dist URLs are keyed by reference; an artifact has no commit
            // sha, so the file's own hash stands in — which also makes a
            // replaced upload a new URL, never a silently different file.
            'reference' => sha1_file($zip),
            'order' => $this->normalizer->order($version),
            'is_dev' => $this->normalizer->isDev($version),
            'released_at' => now(),
            'metadata' => [
                ...array_intersect_key($composerJson, array_flip(self::METADATA_KEYS)),
                'name' => $name,
                'version' => $version,
            ],
        ];

        $model = DB::transaction(function () use ($package, $version, $data, $zip): PackageVersion {
            $model = $package->versions()->updateOrCreate(['version' => $version], $data);

            $this->archives->store($model->setRelation('package', $package), $zip);

            return $model;
        });

        $package->refreshLatestVersion();

        // The latest release describes the package, exactly as a sync would
        // have it.
        if ($package->latest_version === $version || $package->wasRecentlyCreated) {
            $package->forceFill([
                'description' => $composerJson['description'] ?? $package->description,
                'type' => $composerJson['type'] ?? $package->type,
            ])->save();
        }

        return $model;
    }

    /**
     * The decoded composer.json inside the zip, found at the top level or one
     * folder deep — the shape both `git archive` and build tools produce.
     *
     * The manifest is the only entry ever inflated here; the archive itself is
     * stored and streamed byte for byte, never extracted. So the compression
     * ratio of everything *else* inside it is a consuming Composer client's
     * exposure, shared with every archive synced from a VCS provider, and
     * bounded by the upload ceiling rather than by this reader.
     *
     * @return array<string, mixed>
     */
    private function composerJson(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'The upload is not a readable zip archive.']);
        }

        try {
            $manifest = null;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = (string) $zip->getNameIndex($index);

                if (! preg_match('#^(?:[^/]+/)?composer\.json$#', $entry)) {
                    continue;
                }

                // A top-level manifest beats one inside a folder, so a zip
                // that carries both publishes the package it obviously is.
                if ($manifest === null || substr_count($entry, '/') < substr_count($manifest, '/')) {
                    $manifest = $entry;
                }
            }

            if ($manifest === null) {
                throw ValidationException::withMessages([
                    'file' => 'The zip contains no composer.json at its top level or one folder deep.',
                ]);
            }

            $declared = $zip->statName($manifest);

            // The central directory carries every entry's uncompressed size, so
            // an implausible manifest is refused before libzip inflates a byte
            // of it. composer.json is the one entry this app reads into memory,
            // which makes it the one entry a bomb would aim at: a few kilobytes
            // of deflated zeroes can declare gigabytes behind them. An entry
            // that was just enumerated but cannot be stat'ed is an archive not
            // worth trusting either.
            if ($declared === false || $declared['size'] > self::MAX_MANIFEST_BYTES) {
                throw ValidationException::withMessages([
                    'file' => 'The zip\'s composer.json is not a plausible package manifest: a manifest is kilobytes, '
                        .'and anything over '.Number::fileSize(self::MAX_MANIFEST_BYTES).' is refused unread.',
                ]);
            }

            // That size is the archive's own claim about itself, so the read is
            // capped at the same bound: a lying header then truncates into
            // invalid JSON rather than into the worker's memory.
            $decoded = json_decode((string) $zip->getFromName($manifest, self::MAX_MANIFEST_BYTES), true);

            if (! is_array($decoded)) {
                throw ValidationException::withMessages(['file' => 'The composer.json inside the zip is not valid JSON.']);
            }

            return $decoded;
        } finally {
            $zip->close();
        }
    }
}
