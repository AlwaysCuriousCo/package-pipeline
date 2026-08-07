<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Services\GitHub\GitHubClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
     * Proxy a version's zipball from GitHub, caching it locally so each
     * commit is only downloaded once.
     */
    public function dist(string $vendor, string $package, string $reference): BinaryFileResponse
    {
        $name = "{$vendor}/{$package}";

        $record = Package::query()->where('name', $name)->first();

        abort_unless($record instanceof Package, 404, "Package {$name} is not served by this repository.");
        abort_unless(
            $record->versions()->where('reference', $reference)->exists(),
            404,
            "Reference {$reference} is not a known version of {$name}.",
        );

        $disk = Storage::disk('local');
        $path = $disk->path("composer-dists/{$vendor}/{$package}/{$reference}.zip");

        if (! $disk->exists("composer-dists/{$vendor}/{$package}/{$reference}.zip")) {
            File::ensureDirectoryExists(dirname($path));

            try {
                GitHubClient::for($record)->downloadZipball($reference, $path);
            } catch (\Throwable $exception) {
                // A failed download must not leave a partial zip behind to be
                // served as a cache hit on the next request.
                File::delete($path);

                throw $exception;
            }
        }

        return response()->download($path, "{$vendor}-{$package}-{$reference}.zip", [
            'Content-Type' => 'application/zip',
        ]);
    }
}
