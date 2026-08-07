<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Services\GitHub\GitHubClient;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the app as a Composer v2 repository, so a project can point at it
 * with a `composer config repositories.private composer <app-url>` entry.
 */
class ComposerRepositoryController extends Controller
{
    /**
     * The repository root that Composer fetches first.
     */
    public function root(): JsonResponse
    {
        return response()->json([
            'metadata-url' => '/p2/%package%.json',
            'available-packages' => Package::query()->has('versions')->orderBy('name')->pluck('name'),
        ]);
    }

    /**
     * Version metadata for one package. Composer requests both
     * `vendor/name.json` (releases) and `vendor/name~dev.json` (branches).
     */
    public function metadata(string $vendor, string $package): JsonResponse
    {
        $dev = str_ends_with($package, '~dev');
        $name = "{$vendor}/".($dev ? substr($package, 0, -4) : $package);

        $record = Package::query()->where('name', $name)->first();

        abort_unless($record instanceof Package, 404, "Package {$name} is not served by this repository.");

        $versions = $record->versions()
            ->where('is_dev', $dev)
            ->orderByDesc('version')
            ->get()
            ->map(fn (PackageVersion $version): array => [
                ...$version->metadata,
                'name' => $name,
                'dist' => [
                    'type' => 'zip',
                    'url' => route('composer.dist', [
                        'vendor' => $vendor,
                        'package' => explode('/', $name)[1],
                        'reference' => $version->reference,
                    ]),
                    'reference' => $version->reference,
                ],
            ]);

        return response()->json(['packages' => [$name => $versions]]);
    }

    /**
     * Proxy a version's zipball from GitHub, caching it on the dist disk so
     * each commit is only downloaded once.
     */
    public function dist(string $vendor, string $package, string $reference): StreamedResponse
    {
        $name = "{$vendor}/{$package}";

        $record = Package::query()->where('name', $name)->first();

        abort_unless($record instanceof Package, 404, "Package {$name} is not served by this repository.");
        abort_unless(
            $record->versions()->where('reference', $reference)->exists(),
            404,
            "Reference {$reference} is not a known version of {$name}.",
        );

        $disk = Storage::disk(config('filesystems.dists'));
        $path = "composer-dists/{$vendor}/{$package}/{$reference}.zip";

        if (! $disk->exists($path)) {
            $this->cacheZipball($disk, $path, $record, $reference);
        }

        return $disk->download($path, "{$vendor}-{$package}-{$reference}.zip", [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * Download a zipball to a temporary file and stream it onto the dist disk.
     *
     * Going via a temporary file keeps the whole archive out of memory and
     * means a failed download never publishes a partial zip that a later
     * request would serve as a cache hit.
     */
    private function cacheZipball(Filesystem $disk, string $path, Package $record, string $reference): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'composer-dist-');

        throw_if($temporary === false, new \RuntimeException('Unable to create a temporary file for the zipball.'));

        try {
            GitHubClient::for($record)->downloadZipball($reference, $temporary);

            $stream = fopen($temporary, 'r');

            throw_if($stream === false, new \RuntimeException("Unable to read the downloaded zipball for {$reference}."));

            try {
                throw_if(
                    $disk->writeStream($path, $stream) === false,
                    new \RuntimeException("Unable to write {$path} to the dist disk."),
                );
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        } finally {
            File::delete($temporary);
        }
    }
}
