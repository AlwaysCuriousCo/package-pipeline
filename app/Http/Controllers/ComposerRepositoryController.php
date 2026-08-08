<?php

namespace App\Http\Controllers;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Services\GitHub\GitHubClient;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        // `search` and `list` rather than `available-packages`: inlining every
        // name defeats Composer's lazy metadata loading and hands the full
        // package list to anyone who fetches the root.
        return response()->json([
            'metadata-url' => '/p2/%package%.json',
            'search' => url('/search.json').'?q=%query%&type=%type%',
            'list' => url('/list.json'),
        ]);
    }

    /**
     * Search served packages by name prefix and optional Composer type,
     * mirroring packagist.org's search.json shape.
     */
    public function search(Request $request): JsonResponse
    {
        // `%` and `_` in the query are literals, not wildcards — a search for
        // "acme/%" must not enumerate the registry.
        $prefix = addcslashes($request->string('q')->toString(), '\\%_');

        $results = $this->servedPackages()
            ->whereLike('name', "{$prefix}%")
            ->when(
                $request->filled('type'),
                fn (Builder $query) => $query->where('type', $request->string('type')->toString()),
            )
            ->orderBy('name')
            ->get(['name', 'description'])
            ->map(fn (Package $package): array => [
                'name' => $package->name,
                'description' => (string) $package->description,
                // Downloads are not tracked yet; the key is part of the
                // packagist.org response shape, so it is served as zero
                // rather than omitted.
                'downloads' => 0,
            ]);

        return response()->json([
            'total' => $results->count(),
            'results' => $results,
        ]);
    }

    /**
     * Every package name this repository serves.
     */
    public function list(): JsonResponse
    {
        return response()->json([
            'packageNames' => $this->servedPackages()->orderBy('name')->pluck('name'),
        ]);
    }

    /**
     * Packages the repository actually serves. A package with no synced
     * versions resolves to nothing, so advertising it in search or list
     * results would only produce dead ends.
     *
     * @return Builder<Package>
     */
    private function servedPackages(): Builder
    {
        return Package::query()->has('versions');
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
                // Composer reads release dates from `time`. The column is the
                // source of truth, so the served date can never drift from it;
                // a version synced before the date was tracked omits the key
                // rather than advertising a null.
                ...($version->released_at ? ['time' => $version->released_at->toIso8601String()] : []),
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
            } catch (\Throwable $exception) {
                // A write that fails part way through can leave a truncated
                // object behind, which the next request would serve as a
                // cache hit.
                $disk->delete($path);

                throw $exception;
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
