<?php

namespace App\Http\Controllers;

use App\Enums\Ecosystem;
use App\Events\PackageDownloaded;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Repository;
use App\Models\ReservedVendor;
use App\Models\Token;
use App\Services\ArchiveStore;
use App\Services\CreateNpmVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves the app as an npm registry, so a project can point at it with:
 *   npm config set registry <this-app's-url>/npm/
 * or scope it:
 *   npm config set @acme:registry <this-app's-url>/npm/
 * with the token as //host/npm/:_authToken, which arrives as the same bearer
 * credential the Composer endpoints already read.
 *
 * The same repository mounts, tokens, visibility scoping, archive storage and
 * download accounting as the Composer surface — only the protocol layer is
 * npm's: a packument per package, a tarball per version, and `npm publish`'s
 * PUT. Mounted under /npm inside each repository's prefix, because npm's
 * paths are relative to whatever registry URL the client was configured with
 * rather than dictated absolute paths, which Composer's are.
 *
 * @see ComposerRepositoryController the sibling surface this mirrors
 */
class NpmRegistryController extends Controller
{
    /**
     * What this registry accepts as an npm package name: a bare lowercase
     * segment or a scoped one. Deliberately disjoint from Composer's
     * "vendor/name" shape — a slash only ever follows an @scope — which is
     * what lets both ecosystems share one (repository_id, name) namespace
     * without colliding. 214 is npm's own ceiling.
     */
    private const NAME_PATTERN = '/^(?:@[a-z0-9][a-z0-9._-]*\/)?[a-z0-9][a-z0-9._-]*$/';

    public function __construct(
        private readonly ArchiveStore $archives,
    ) {}

    /**
     * The packument — every version's manifest, the dist-tags, and the
     * tarball URLs. The one document `npm install` resolves from.
     *
     * No conditional-request validators yet, unlike /p2: npm's client cache
     * revalidates less aggressively than Composer's and correctness does not
     * depend on them. ponytail: add the ETag/payload-cache pair from the
     * Composer surface when a registry serves enough npm traffic to feel it.
     */
    public function packument(Request $request, string $name): JsonResponse
    {
        $repository = $this->repository($request);

        $record = $this->servedPackage($request, $repository, $name);

        $versions = [];
        $time = [];

        foreach ($record->versions()->orderedByVersion('asc')->get() as $version) {
            $versions[$version->version] = $this->manifest($repository, $record, $version);

            if ($version->released_at !== null) {
                $time[$version->version] = $version->released_at->utc()->toIso8601String();
            }
        }

        abort_if($versions === [], 404, "Package {$name} has no published versions.");

        // The stored name, not the URL's spelling, for the reason /p2 serves
        // it: the body must be a function of what is stored.
        $stored = (string) $record->name;

        // `latest` is what a bare `npm install` resolves, so it must always
        // exist: the recorded latest release, or the highest version stored.
        $latest = $record->latest_version !== null && isset($versions[$record->latest_version])
            ? $record->latest_version
            : (string) array_key_last($versions);

        return response()->json([
            'name' => $stored,
            'description' => (string) $record->description,
            'dist-tags' => ['latest' => $latest],
            'versions' => $versions,
            'time' => (object) $time,
        ], 200, [
            // Private for the reason every Composer response is: what this
            // URL answers depends on who asked.
            'Cache-Control' => 'private, no-cache',
            'Vary' => 'Authorization',
        ]);
    }

    /**
     * One version's manifest as the packument serves it: what was published,
     * plus a `dist` built fresh for the mount the request came through.
     *
     * @return array<string, mixed>
     */
    private function manifest(Repository $repository, Package $package, PackageVersion $version): array
    {
        $manifest = $version->metadata;

        // Stored beside the manifest at publish; served inside dist, where
        // npm reads it. @see CreateNpmVersion
        $integrity = $manifest['_integrity'] ?? null;
        unset($manifest['_integrity']);

        return [
            ...$manifest,
            'name' => (string) $package->name,
            'version' => $version->version,
            'dist' => [
                'tarball' => $repository->url($this->tarballPath($package, $version)),
                ...($version->shasum ? ['shasum' => $version->shasum] : []),
                ...(is_string($integrity) ? ['integrity' => $integrity] : []),
            ],
        ];
    }

    /**
     * The path a version's tarball is served at, inside the repository's
     * mount. npm's convention — /{name}/-/{basename}-{version}.tgz — which
     * tarball() below parses back apart.
     */
    private function tarballPath(Package $package, PackageVersion $version): string
    {
        return "/npm/{$package->name}/-/".ArchiveStore::slug((string) $package->name)."-{$version->version}.tgz";
    }

    /**
     * Serve a version's stored tarball, exactly as the Composer dist endpoint
     * serves a zip: visibility first, the download counted only for a GET,
     * and the transfer handed to the disk when it can sign URLs of its own.
     */
    public function tarball(Request $request, string $name, string $filename): StreamedResponse|RedirectResponse
    {
        $repository = $this->repository($request);

        $record = $this->servedPackage($request, $repository, $name);

        // The filename is {basename}-{version}.tgz; the basename is known, so
        // what is left between it and the suffix is the version asked for.
        $stem = Str::beforeLast($filename, '.tgz');
        $prefix = ArchiveStore::slug((string) $record->name).'-';

        abort_unless(str_starts_with($stem, $prefix), 404, "No tarball named {$filename} belongs to {$record->name}.");

        $versionString = substr($stem, strlen($prefix));

        $version = $record->versions()
            ->where('version', $versionString)
            ->whereNotNull('archive_path')
            ->first();

        abort_unless(
            $version instanceof PackageVersion && $this->archives->disk()->exists((string) $version->archive_path),
            404,
            "No archive is stored for {$record->name}@{$versionString}.",
        );

        if ($request->isMethod('GET')) {
            PackageDownloaded::dispatch(
                $record->id,
                $version->id,
                $version->version,
                $this->token($request)?->token_prefix,
            );
        }

        $download = ArchiveStore::downloadFilename((string) $record->name, $version->version, 'tgz');

        $url = $this->archives->temporaryUrl((string) $version->archive_path, $download);

        if ($url !== null) {
            return redirect()->away($url, headers: ['Cache-Control' => 'no-store']);
        }

        return $this->archives->disk()->download((string) $version->archive_path, $download, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    /**
     * `npm publish` — a PUT of one document carrying the version manifest and
     * the tarball as a base64 attachment.
     *
     * Requires a token with the write ability; the middleware never lets an
     * unauthenticated request through to here. The same scope rules as the
     * Composer upload: an existing package is a write to that package, a new
     * name is a write to the repository.
     */
    public function publish(Request $request, CreateNpmVersion $creator, string $name): JsonResponse
    {
        $name = mb_strtolower(rawurldecode($name));

        abort_unless(
            preg_match(self::NAME_PATTERN, $name) === 1 && strlen($name) <= 214,
            400,
            "\"{$name}\" is not a valid npm package name.",
        );

        $document = $request->json()->all();

        if (($document['name'] ?? null) !== $name) {
            throw ValidationException::withMessages([
                'name' => "The published document names \"".($document['name'] ?? '')."\", but the publish is addressed to \"{$name}\".",
            ]);
        }

        $versions = is_array($document['versions'] ?? null) ? $document['versions'] : [];

        // npm publishes exactly one version per request; refusing more is not
        // a lost feature, it is the protocol.
        if (count($versions) !== 1 || ! is_array(reset($versions))) {
            throw ValidationException::withMessages([
                'versions' => 'A publish must carry exactly one version manifest.',
            ]);
        }

        $repository = $this->repository($request);

        $existing = $repository->packages()->where('packages.name', $name)->first();

        // ponytail: names are unique per repository across ecosystems — an
        // unscoped npm name can collide with a PyPI project's — so the wrong
        // ecosystem's package refuses the name rather than tripping the
        // unique index; scope the indexes by ecosystem if that bites.
        abort_if(
            $existing instanceof Package && $existing->ecosystem !== Ecosystem::Npm,
            409,
            "\"{$name}\" is already served by this repository as a {$existing?->ecosystem->value} package; one repository answers for one package per name.",
        );

        abort_unless(
            $this->mayPublishTo($request, $repository, $existing),
            403,
            'This token may not publish into this repository.',
        );

        // A scoped name's @scope is its vendor, and a reservation governs
        // what may be introduced under one — only for a name nothing here
        // serves yet, exactly as the Composer upload has it.
        if (! $existing instanceof Package) {
            $conflict = ReservedVendor::conflictFor($name, (int) $repository->id);

            abort_if($conflict instanceof ReservedVendor, 403, $conflict?->refusal($name) ?? '');
        }

        $tarball = $this->attachedTarball($document);

        try {
            $version = $creator->create($repository, $name, (array) reset($versions), $tarball);
        } finally {
            @unlink($tarball);
        }

        return response()->json([
            'name' => $name,
            'version' => $version->version,
            'shasum' => $version->shasum,
        ], 201);
    }

    /**
     * The publish document's tarball, decoded onto local disk — returning the
     * path, which the caller owns and unlinks.
     *
     * @param  array<string, mixed>  $document
     */
    private function attachedTarball(array $document): string
    {
        $attachments = is_array($document['_attachments'] ?? null) ? $document['_attachments'] : [];
        $attachment = reset($attachments);

        $data = is_array($attachment) ? ($attachment['data'] ?? null) : null;

        // Strict, because a tarball that silently dropped invalid characters
        // would store bytes whose shasum matches nothing the client sent.
        $bytes = is_string($data) ? base64_decode($data, true) : false;

        if ($bytes === false || $bytes === '') {
            throw ValidationException::withMessages([
                '_attachments' => 'The publish carries no readable tarball attachment.',
            ]);
        }

        // The same ceiling the Composer upload enforces, applied to the
        // decoded size — the base64 wrapper is a third bigger and not what
        // an operator sizing a package thinks in.
        $ceiling = (int) config('registry.upload_max_megabytes') * 1024 * 1024;

        if (strlen($bytes) > $ceiling) {
            throw ValidationException::withMessages([
                '_attachments' => 'The tarball exceeds the '.((int) config('registry.upload_max_megabytes')).' MB upload ceiling.',
            ]);
        }

        $path = (string) tempnam(sys_get_temp_dir(), 'npm-publish-');

        file_put_contents($path, $bytes);

        return $path;
    }

    /**
     * Whether the authenticated principal's scope reaches this publish —
     * the same two rules the Composer upload applies.
     *
     * @see Token::mayWriteTo() where both live
     */
    private function mayPublishTo(Request $request, Repository $repository, ?Package $existing): bool
    {
        $token = $this->token($request);

        if (! $token instanceof Token) {
            return false;
        }

        return $existing instanceof Package
            ? $token->mayWriteToPackage($existing)
            : $token->mayWriteTo($repository);
    }

    /**
     * The npm package this repository serves under the name, refused with the
     * same 404 whether it is absent or merely invisible to this principal.
     */
    private function servedPackage(Request $request, Repository $repository, string $name): Package
    {
        // Lowercased for the reason the Composer endpoints fold their URLs:
        // the stored name is canonical and the lookup is an indexed equality.
        // rawurldecode() for the client that sends a scoped name with its
        // slash still encoded after the router declined to decode it.
        $name = mb_strtolower(rawurldecode($name));

        $record = $repository->packages()
            ->ofEcosystem(Ecosystem::Npm)
            ->visibleTo($this->token($request), $repository)
            ->where('name', $name)
            ->first();

        abort_unless($record instanceof Package, 404, "Package {$name} is not served by this registry.");

        return $record;
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
}
