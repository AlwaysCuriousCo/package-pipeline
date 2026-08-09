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
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
    /**
     * Bumped whenever the rendered `/p2` document changes shape.
     *
     * A metadata response's validators are derived from the database rather
     * than from the bytes, so nothing else would tell a client whose copy
     * predates a deploy that what it holds is no longer what this code sends.
     */
    private const PAYLOAD_REVISION = 1;

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
     *
     * The one endpoint a `composer update` hammers, so it answers conditional
     * requests: Composer's downloader sends `If-Modified-Since` on every
     * metadata refetch, and a 304 here costs one aggregate instead of every
     * version row, every `metadata` column decoded, and the megabytes they
     * render back into.
     *
     * The other three read endpoints deliberately get no validators.
     * `packages.json` is three URL templates built from a row already in
     * memory, so a 304 would skip no work at all. `list.json` and
     * `search.json` answer a *set* of packages chosen by the caller's grants,
     * and there is no cheap fingerprint of that set — the query that would
     * produce one is the query that answers them.
     */
    public function metadata(Request $request, string $vendor, string $package): Response
    {
        $repository = $this->repository($request);

        $dev = str_ends_with($package, '~dev');
        $name = "{$vendor}/".($dev ? substr($package, 0, -4) : $package);

        $record = $repository->packages()
            ->visibleTo($this->token($request))
            ->where('name', $name)
            ->first();

        // Visibility is settled before a validator is so much as computed, so
        // a client that may not see this package gets the 404 it always got
        // and never a 304 confirming the package is here.
        abort_unless($record instanceof Package, 404, "Package {$name} is not served by this repository.");

        $state = $this->versionState($record, $dev);

        $response = response('', 200, [
            'Content-Type' => 'application/json',
            // `no-cache` rather than a freshness lifetime: a response carrying
            // Last-Modified and no max-age is fair game for heuristic
            // freshness, and a cache inventing one would go on serving this
            // package's metadata to a token that has since been revoked.
            // `private` keeps it out of shared caches altogether, because what
            // this URL answers depends on who asked — which is also what Vary
            // says to anything that stores it regardless.
            'Cache-Control' => 'private, no-cache',
            'Vary' => 'Authorization',
        ]);

        $etag = $this->etag($repository, $record, $dev, $state);

        $response->setLastModified($this->lastModified($record, $state));
        $response->setEtag($etag, weak: true);

        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response->setContent($this->payload($repository, $record, $dev, $etag));
    }

    /**
     * The bytes to serve for one metadata request, rendered at most once per
     * change to the package.
     *
     * Keyed by the fingerprint the ETag was cut from, which is what makes this
     * safe: the entry is not invalidated, it is *superseded* — a sync, an
     * upload, a prune or a deploy that changes the rendering all produce a
     * different key, and the same aggregate that decided the validators
     * decided the key. Busting explicitly would mean naming every write path
     * (the synchronizer's import, prune and finalize, the archive store, the
     * upload creator, version and package deletion) and being right about all
     * of them forever — and a registry serving one package's stale metadata
     * is the worst failure this app has.
     *
     * Only the payload is cached. Who may see it is decided per request, above.
     */
    private function payload(Repository $repository, Package $package, bool $dev, string $etag): string
    {
        $key = 'composer:metadata:'.$package->getKey().':'.($dev ? 'dev' : 'stable').":{$etag}";

        $cached = cache()->get($key);

        if (is_string($cached)) {
            return $cached;
        }

        $json = $this->renderMetadata($repository, $package, $dev);

        $ceiling = (int) config('registry.metadata_cache.max_kilobytes') * 1024;

        if (strlen($json) <= $ceiling) {
            cache()->put($key, $json, now()->addDays((int) config('registry.metadata_cache.days')));
        }

        return $json;
    }

    /**
     * A cheap fingerprint of the version rows one metadata response is built
     * from: how many there are, when one of them last changed, and the
     * highest row id among them.
     *
     * One aggregate and not a single row, which is the entire point — a 304
     * that had to read every version's `metadata` column to decide it was a
     * 304 would cost exactly what sending the body costs.
     *
     * All three parts are load-bearing. The count alone misses an edit; the
     * timestamp alone misses a deletion, and being second-resolution it also
     * misses a row replaced inside one second, which the id catches.
     *
     * @return array{count: int, changed: ?CarbonImmutable, newest: int}
     */
    private function versionState(Package $package, bool $dev): array
    {
        $state = (array) $package->versions()
            ->where('is_dev', $dev)
            ->toBase()
            ->selectRaw('count(*) as version_count, max(updated_at) as changed_at, coalesce(max(id), 0) as newest_id')
            ->first();

        $changed = $state['changed_at'] ?? null;

        return [
            'count' => (int) ($state['version_count'] ?? 0),
            'changed' => is_string($changed) ? CarbonImmutable::parse($changed) : null,
            'newest' => (int) ($state['newest_id'] ?? 0),
        ];
    }

    /**
     * When this package's metadata last changed.
     *
     * The package's own timestamp is in the max because a *deleted* version
     * leaves no timestamp behind. Every path that removes one — a sync
     * pruning refs gone from upstream, an import dropping a ref whose
     * composer.json lost its name — finishes by saving the package, so what
     * moves when the version rows only get fewer is the package row.
     *
     * @param  array{count: int, changed: ?CarbonImmutable, newest: int}  $state
     */
    private function lastModified(Package $package, array $state): ?DateTimeInterface
    {
        $timestamps = array_filter([$package->updated_at, $state['changed']]);

        return $timestamps === [] ? null : max($timestamps);
    }

    /**
     * A weak validator for a metadata response.
     *
     * Weak deliberately: it is derived from the fingerprint above rather than
     * from a digest of the bytes, because hashing the payload would mean
     * building the payload — the work the validator exists to skip. Weakness
     * is precisely the claim being made, and revalidation is the only thing
     * either Composer or an intermediary ever uses this for.
     *
     * The dist URLs are baked into the body, so the base they are built from
     * is part of what the tag identifies — a repository moved to another path
     * serves different bytes from the same rows.
     *
     * @param  array{count: int, changed: ?CarbonImmutable, newest: int}  $state
     */
    private function etag(Repository $repository, Package $package, bool $dev, array $state): string
    {
        // xxh128 because this is a cache validator, not a signature: it has to
        // be collision-resistant against accident, never against an attacker.
        return hash('xxh128', implode('|', [
            self::PAYLOAD_REVISION,
            $repository->url('/dist/'),
            $package->getKey(),
            $dev ? 'dev' : 'stable',
            $state['count'],
            $state['changed']?->getTimestamp() ?? 0,
            $state['newest'],
        ]));
    }

    /**
     * The `/p2` document for one flavour of one package, as the bytes to send.
     *
     * Serves the package's stored name rather than the spelling the URL asked
     * for: on a case-insensitive collation the two need not match, and the
     * body has to be a function of what is stored, not of how it was typed —
     * otherwise two spellings of one package share a validator and differ in
     * what they answer.
     */
    private function renderMetadata(Repository $repository, Package $package, bool $dev): string
    {
        $name = (string) $package->name;

        $versions = $package->versions()
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
                    'url' => $repository->url("/dist/{$name}/{$version->reference}.zip"),
                    'reference' => $version->reference,
                    // Composer verifies the downloaded zip against this. A
                    // version synced before archives were stored has none, and
                    // omitting the key beats advertising a null.
                    ...($version->shasum ? ['shasum' => $version->shasum] : []),
                ],
            ])
            ->values()
            ->all();

        return json_encode(['packages' => [$name => $versions]], JSON_THROW_ON_ERROR);
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
        // Laravel sizes file rules in kilobytes; the ceiling is configured in
        // megabytes, which is the unit an operator sizing a package thinks in.
        $maxKilobytes = (int) config('registry.upload_max_megabytes') * 1024;

        $validated = $request->validate([
            // Without a max: rule the only bound is php.ini's
            // upload_max_filesize — a deployment default rather than a decision
            // this app made, and absent entirely once an operator raises it for
            // one large package. See config/registry.php for the number.
            'file' => ['required', 'file', "max:{$maxKilobytes}", 'mimes:zip'],
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
