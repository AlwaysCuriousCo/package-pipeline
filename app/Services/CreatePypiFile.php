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
 * Publishes one Python distribution file — what a `twine upload` POSTs.
 *
 * The third publisher beside CreateVersionFromZip and CreateNpmVersion, with
 * one wrinkle of its own: a Python release is several files, not one. A
 * version usually ships as an sdist *and* a wheel, and pip picks whichever
 * suits the installing platform — so a version row here accumulates a `files`
 * list in its metadata, one entry per uploaded filename, while `archive_path`
 * goes on holding the most recently stored of them for the panel and the
 * sweeps that read it. archives:clean reads the list too, so the earlier
 * files of a version are never mistaken for orphans.
 */
class CreatePypiFile
{
    public function __construct(
        private readonly ArchiveStore $archives,
        private readonly VersionNormalizer $normalizer = new VersionNormalizer,
    ) {}

    /**
     * Record one uploaded file against its version, creating the package and
     * the version as needed, returning the stored row.
     *
     * @param  string  $name  the PEP 503-normalized project name
     * @param  string  $file  path to the uploaded distribution on local disk
     * @param  string  $filename  what pip will know the file as, verbatim
     */
    public function create(
        Repository $repository,
        string $name,
        string $version,
        string $file,
        string $filename,
        ?string $summary = null,
        ?string $requiresPython = null,
    ): PackageVersion {
        $package = $repository->packages()
            ->ofEcosystem(Ecosystem::Pypi)
            ->where('packages.name', $name)
            ->first()
            ?? Package::query()->create([
                'repository_id' => $repository->id,
                'ecosystem' => Ecosystem::Pypi,
                'name' => $name,
                'description' => $summary,
                'webhook_enabled' => false,
            ]);

        $sha256 = hash_file('sha256', $file);

        if ($sha256 === false) {
            throw ValidationException::withMessages(['content' => 'The uploaded file could not be read.']);
        }

        $model = DB::transaction(function () use ($package, $version, $file, $filename, $sha256, $summary, $requiresPython): PackageVersion {
            $model = $package->versions()->firstOrNew(['version' => $version]);

            // Replace an entry re-uploaded under the same filename, keep the
            // rest. The list, not `archive_path`, is what the simple index
            // serves — every file of the version, each with the sha256 pip
            // verifies the download against.
            $files = array_values(array_filter(
                (array) (($model->metadata ?? [])['files'] ?? []),
                fn (mixed $entry): bool => ! is_array($entry) || ($entry['filename'] ?? null) !== $filename,
            ));

            $metadata = fn (array $files): array => [
                ...($model->metadata ?? []),
                'name' => (string) $package->name,
                'version' => $version,
                ...(filled($summary) ? ['summary' => (string) $summary] : []),
                'files' => $files,
            ];

            // Filled — metadata included — before store(), which saves the
            // row: a fresh version has to be insertable by then. The new
            // file's own entry has to wait for the path store() assigns.
            $model->fill([
                'reference' => sha1_file($file),
                'order' => $this->normalizer->order($version),
                'is_dev' => false,
                'released_at' => $model->released_at ?? now(),
                'metadata' => $metadata($files),
            ]);

            $this->archives->store($model->setRelation('package', $package), $file, $this->extension($filename));

            $files[] = [
                'filename' => $filename,
                'path' => $model->archive_path,
                'sha256' => $sha256,
                ...(filled($requiresPython) ? ['requires_python' => (string) $requiresPython] : []),
            ];

            $model->fill(['metadata' => $metadata($files)])->save();

            return $model;
        });

        $package->refreshLatestVersion();

        if (filled($summary) && ($package->latest_version === $version || $package->wasRecentlyCreated)) {
            $package->forceFill(['description' => $summary])->save();
        }

        return $model;
    }

    /**
     * What the stored object's key should admit to holding. Cosmetic, as the
     * npm tarball's suffix is — the sweeps reconcile by path.
     */
    private function extension(string $filename): string
    {
        return match (true) {
            str_ends_with($filename, '.tar.gz') => 'tar.gz',
            default => pathinfo($filename, PATHINFO_EXTENSION) ?: 'bin',
        };
    }
}
