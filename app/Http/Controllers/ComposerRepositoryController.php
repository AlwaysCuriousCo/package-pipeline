<?php

namespace App\Http\Controllers;

use App\Events\PackageDownloaded;
use App\Http\Middleware\ResolveComposerRepository;
use App\Models\DeployToken;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Repository;
use App\Models\Token;
use App\Models\User;
use App\Services\ArchiveStore;
use App\Services\CreateVersionFromZip;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the app as a Composer v2 repository, so a project can point at it
 * with a `composer config repositories.private composer <app-url>` entry.
 *
 * Every action serves one Repository — resolved by the middleware from the
 * mount the request arrived through — and scopes all of its queries to it,
 * so /r/internal and the root are entirely separate registries.
 *
 * @see ResolveComposerRepository
 */
class ComposerRepositoryController extends Controller
{
    public function __construct(private readonly ArchiveStore $archives) {}

    /**
     * The repository root that Composer fetches first.
     */
    public function root(Request $request): JsonResponse
    {
        $repository = $this->repository($request);

        // `search` and `list` rather than `available-packages`: inlining every
        // name defeats Composer's lazy metadata loading and hands the full
        // package list to anyone who fetches the root.
        return response()->json([
            'metadata-url' => $repository->pathPrefix().'/p2/%package%.json',
            'search' => $repository->url('/search.json').'?q=%query%&type=%type%',
            'list' => $repository->url('/list.json'),
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

        $results = $this->servedPackages($request)
            ->whereLike('name', "{$prefix}%")
            ->when(
                $request->filled('type'),
                fn (Builder $query) => $query->where('type', $request->string('type')->toString()),
            )
            ->orderBy('name')
            ->get(['name', 'description', 'total_downloads'])
            ->map(fn (Package $package): array => [
                'name' => $package->name,
                'description' => (string) $package->description,
                'downloads' => $package->total_downloads,
            ]);

        return response()->json([
            'total' => $results->count(),
            'results' => $results,
        ]);
    }

    /**
     * Every package name this repository serves.
     */
    public function list(Request $request): JsonResponse
    {
        return response()->json([
            'packageNames' => $this->servedPackages($request)->orderBy('name')->pluck('name'),
        ]);
    }

    /**
     * Version metadata for one package. Composer requests both
     * `vendor/name.json` (releases) and `vendor/name~dev.json` (branches).
     */
    public function metadata(Request $request, string $vendor, string $package): JsonResponse
    {
        $repository = $this->repository($request);

        $dev = str_ends_with($package, '~dev');
        $name = "{$vendor}/".($dev ? substr($package, 0, -4) : $package);

        $record = $repository->packages()
            ->visibleTo($this->token($request))
            ->where('name', $name)
            ->first();

        abort_unless($record instanceof Package, 404, "Package {$name} is not served by this repository.");

        $versions = $record->versions()
            ->where('is_dev', $dev)
            // Releases sort by the normalizer's order string, whose lexical
            // order is semantic order (1.10.0 above 1.9.0). Branches have no
            // release line to sort along, so dev versions keep name order.
            ->when($dev, fn (Builder $query) => $query->orderByDesc('version'))
            ->unless($dev, fn (Builder $query) => $query->orderByDesc('order'))
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
                    'url' => $repository->url(
                        '/dist/'.$vendor.'/'.explode('/', $name)[1]."/{$version->reference}.zip",
                    ),
                    'reference' => $version->reference,
                    // Composer verifies the downloaded zip against this. A
                    // version synced before archives were stored has none, and
                    // omitting the key beats advertising a null.
                    ...($version->shasum ? ['shasum' => $version->shasum] : []),
                ],
            ]);

        return response()->json(['packages' => [$name => $versions]]);
    }

    /**
     * Stream a version's stored archive from the dist disk.
     *
     * Archives are built at sync time, so serving never reaches for GitHub —
     * a consumer needs no GitHub credentials, and an archive that was never
     * stored (or has gone missing) is a 404 the next sync repairs.
     */
    public function dist(Request $request, string $vendor, string $package, string $reference): StreamedResponse
    {
        $name = "{$vendor}/{$package}";

        $record = $this->repository($request)->packages()
            ->visibleTo($this->token($request))
            ->where('name', $name)
            ->first();

        abort_unless($record instanceof Package, 404, "Package {$name} is not served by this repository.");

        $versions = $record->versions()->where('reference', $reference)->get();

        abort_if($versions->isEmpty(), 404, "Reference {$reference} is not a known version of {$name}.");

        $disk = $this->archives->disk();

        // A tag and a branch can share a commit; any row with a stored
        // archive serves for both. The disk is asked per row rather than
        // once, because a row's path can outlive its file — a sibling row
        // may still hold a live zip for the same commit.
        $version = $versions->first(
            fn (PackageVersion $version): bool => $version->archive_path !== null
                && $disk->exists($version->archive_path),
        );

        abort_unless(
            $version instanceof PackageVersion,
            404,
            "No archive is stored for {$name}@{$reference}; syncing the package will build it.",
        );

        // Only an archive actually being served counts; every 404 above
        // returned before reaching this line.
        PackageDownloaded::dispatch(
            $record->id,
            $version->id,
            $version->version,
            $request->ip(),
            $this->token($request)?->token_prefix,
        );

        return $disk->download($version->archive_path, "{$vendor}-{$package}-{$reference}.zip", [
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * Publish a version from an uploaded zip — CI pushing what it built.
     *
     * Requires a token with the write ability; the middleware never lets an
     * unauthenticated request through to here.
     */
    public function upload(Request $request, CreateVersionFromZip $creator, string $vendor, string $package): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:zip'],
            'version' => ['nullable', 'string', 'max:255'],
        ]);

        $repository = $this->repository($request);
        $name = mb_strtolower("{$vendor}/{$package}");

        abort_unless(
            $this->mayUploadTo($request, $repository, $name),
            403,
            'This token may not publish into this repository.',
        );

        $version = $creator->create(
            $repository,
            $name,
            $validated['file']->getRealPath(),
            $validated['version'] ?? null,
        );

        return response()->json([
            'name' => $name,
            'version' => $version->version,
            'shasum' => $version->shasum,
        ], 201);
    }

    /**
     * Whether the authenticated principal's scope reaches this repository.
     *
     * The write ability says "may publish"; the scope says where. A scoped
     * principal needs the repository itself granted — or the existing package
     * when only packages were granted. A public repository is readable by
     * anyone, which grants nothing about writing into it.
     */
    private function mayUploadTo(Request $request, Repository $repository, string $name): bool
    {
        $principal = $this->token($request)?->tokenable;

        $existingPackageGranted = fn ($grants): bool => ($existing = $repository->packages()->where('name', $name)->first())
            && $grants->whereKey($existing->id)->exists();

        if ($principal instanceof User) {
            return $principal->hasUnscopedAccess()
                || $principal->repositories()->whereKey($repository->id)->exists()
                || $existingPackageGranted($principal->packages());
        }

        if ($principal instanceof DeployToken) {
            return ! $principal->isScoped()
                || $principal->repositories()->whereKey($repository->id)->exists()
                || $existingPackageGranted($principal->packages());
        }

        return false;
    }

    /**
     * The repository this request is addressed to, resolved by the middleware.
     */
    private function repository(Request $request): Repository
    {
        return $request->attributes->get('composerRepository');
    }

    /**
     * The access token the request authenticated with, if any.
     */
    private function token(Request $request): ?Token
    {
        return $request->attributes->get('composerToken');
    }

    /**
     * Packages the repository actually serves to this request's principal.
     * A package with no synced versions resolves to nothing, so advertising
     * it in search or list results would only produce dead ends.
     *
     * @return Builder<Package>
     */
    private function servedPackages(Request $request): Builder
    {
        return $this->repository($request)->packages()
            ->visibleTo($this->token($request))
            ->has('versions')
            ->getQuery();
    }
}
