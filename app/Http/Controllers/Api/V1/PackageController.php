<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\PackageDetailResource;
use App\Http\Resources\Api\V1\PackageResource;
use App\Jobs\SyncPackageJob;
use App\Models\Package;
use App\Models\Repository;
use App\Models\ReservedVendor;
use App\Services\GitHub\WebhookRegistrar;
use App\Support\ComposerName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The packages this registry serves, for the callers that cannot open a
 * browser: a CI pipeline publishing and then waiting on a sync, and a
 * provisioning script that creates one package per source repository.
 *
 * A package is addressed by its numeric id rather than its Composer name,
 * because a name is only unique *within* a Composer repository — two
 * registries served from one installation may each publish acme/widgets, and a
 * URL that cannot tell them apart would be a coin toss on every delete. The
 * listing takes `name` and `repository` filters so a script that knows only
 * the name can resolve one in a single request.
 */
class PackageController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:255'],
            'repository' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer'],
        ]);

        $packages = $this->packages($request)
            ->when(
                isset($validated['name']),
                fn (Builder $query) => $query->where('name', mb_strtolower((string) $validated['name'])),
            )
            // A prefix match, not a substring one: `%` and `_` are escaped so a
            // search for "acme/%" enumerates nothing, exactly as search.json.
            ->when(
                isset($validated['q']),
                fn (Builder $query) => $query->whereLike('name', addcslashes((string) $validated['q'], '\\%_').'%'),
            )
            ->when(
                isset($validated['type']),
                fn (Builder $query) => $query->where('type', $validated['type']),
            )
            // Present but empty is the root repository, whose path is null —
            // the one repository that cannot be named by a path because it is
            // not mounted under one.
            ->when($request->has('repository'), fn (Builder $query) => $query->whereHas(
                'composerRepository',
                fn (Builder $repositories) => $repositories->where('path', $validated['repository'] ?? null),
            ))
            ->with('composerRepository')
            ->withCount('versions')
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return PackageResource::collection($packages);
    }

    public function show(Request $request, string $package): PackageDetailResource
    {
        $record = $this->find($request, $package);

        // Newest first, the order /p2 serves and the order a caller checking
        // "has my release landed yet" reads.
        $record->load('composerRepository')->loadCount('versions');
        $record->setRelation('versions', $record->versions()->orderedByVersion()->get());

        return new PackageDetailResource($record);
    }

    /**
     * Create a package from a VCS repository URL — the create wizard's job,
     * scriptable, and the same three steps it takes: store the package,
     * arrange a way to hear about its pushes, queue its first sync.
     */
    public function store(Request $request): JsonResponse
    {
        $repository = $this->targetRepository($request);

        // Before the rest of the validation, so a caller who may not write here
        // cannot learn which names are taken from the 422s.
        abort_unless(
            $this->token($request)->mayCreatePackageIn($repository),
            403,
            'This token may not create packages in this repository.',
        );

        // Normalized before the rules run, because it is one of the columns the
        // URL's uniqueness is judged against and the model will fold it the
        // same way on save — a check against the typed spelling would clear a
        // collision the insert then hits as a bare unique-index error.
        $subdirectory = $this->subdirectory($request);

        $validated = $request->validate([
            'url' => [
                'required', 'string', 'url', 'max:255',
                Rule::unique('packages', 'repository')
                    ->where('repository_id', $repository->id)
                    ->where('subdirectory', $subdirectory),
            ],
            'subdirectory' => ['nullable', 'string', 'max:255'],
            'name' => [
                'nullable', 'string', 'max:255', 'regex:'.ComposerName::PATTERN,
                Rule::unique('packages', 'name')->where('repository_id', $repository->id),
            ],
            // Both default on, matching the panel: a package created without a
            // webhook and without a first sync is an empty row that serves
            // nothing and will not notice when that changes.
            'sync' => ['boolean'],
            'webhook' => ['boolean'],
        ], [
            'name.regex' => 'The name must be a Composer package name, like "acme/widgets".',
            'url.unique' => 'This repository URL and subdirectory are already served by a package in this Composer repository.',
            'name.unique' => 'This Composer repository already serves a package with that name.',
        ]);

        $package = new Package([
            'repository_id' => $repository->id,
            'repository' => $validated['url'],
            'subdirectory' => $subdirectory,
            'webhook_enabled' => $request->boolean('webhook', true),
        ]);

        // The composer.json is not read here — that is a network round trip to
        // the provider, and the first sync does it properly a moment later. The
        // URL's own "owner/repo" stands in until then, exactly as it does when
        // the wizard cannot reach the repository either.
        $name = $validated['name'] ?? $package->suggestedName();

        if (blank($name)) {
            throw ValidationException::withMessages([
                'name' => "No Composer name was given and none can be guessed from \"{$validated['url']}\".",
            ]);
        }

        $package->name = mb_strtolower($name);

        // The model refuses this on save too, by throwing; asked here it is a
        // 422 on the field that has to change, like every other name problem
        // this endpoint reports.
        $conflict = ReservedVendor::conflictFor($package->name, $repository->id);

        if ($conflict instanceof ReservedVendor) {
            throw ValidationException::withMessages(['name' => $conflict->refusal($package->name)]);
        }

        $package->save();

        $registrar = app(WebhookRegistrar::class);
        $registrar->register($package);

        $queued = $request->boolean('sync', true) && SyncPackageJob::dispatchUnlessPending($package);

        $package->load('composerRepository')->loadCount('versions');

        return (new PackageDetailResource($package))
            ->additional([
                'sync_queued' => $queued,
                // The panel raises this as a persistent warning, because a
                // package that cannot auto-sync looks exactly like one that
                // can until a release fails to appear weeks later. A CI log is
                // where this one gets read.
                'warnings' => array_filter([$registrar->unmetRequirement($package)]),
            ])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * The subdirectory as Package will store it, empty for a package published
     * from the repository root.
     *
     * A `..` segment is the one shape the model refuses outright, by throwing.
     * Caught here it is a 422 on the field that has to change, like every
     * other bad input this endpoint answers with.
     */
    private function subdirectory(Request $request): string
    {
        $given = $request->input('subdirectory');

        // Anything that is not a string is left to the rule below to reject;
        // it must not blow up on the way to being reported.
        $package = new Package(['subdirectory' => is_string($given) ? $given : '']);

        try {
            $package->normalizeSubdirectory();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['subdirectory' => $exception->getMessage()]);
        }

        return (string) $package->subdirectory;
    }

    /**
     * Queue a sync — what CI calls after pushing a tag, when the registry has
     * no webhook on the repository to hear it from.
     */
    public function sync(Request $request, string $package): JsonResponse
    {
        $request->validate(['force' => ['boolean']]);

        $record = $this->find($request, $package);

        abort_unless(
            $this->token($request)->maySyncPackage($record),
            403,
            'This token may not sync this package.',
        );

        if (blank($record->repository)) {
            throw ValidationException::withMessages([
                'package' => "\"{$record->name}\" is published by artifact upload and has no source to sync from.",
            ]);
        }

        // False when a sync is already queued for this package, which is a
        // perfectly good outcome rather than an error: the run already waiting
        // will read the same refs this one would have. Reported so a caller
        // polling for completion knows which run it is watching.
        $queued = SyncPackageJob::dispatchUnlessPending($record, force: $request->boolean('force'));

        $record->load('composerRepository')->loadCount('versions');

        return (new PackageDetailResource($record))
            ->additional(['sync_queued' => $queued])
            ->response()
            // Accepted, not OK: the versions are imported by a worker, and the
            // package in this body is the one from before that ran.
            ->setStatusCode(202);
    }

    /**
     * Delete a package, its versions and its stored archives.
     *
     * Behind its own ability. Every other mutation here is additive or
     * repeatable; this one unpublishes something consuming projects may have
     * pinned in a lock file, and no amount of syncing brings it back.
     *
     * And behind the panel's Delete:Package as well, for a token issued by a
     * person: the ability says this credential was meant for deleting, not
     * that its owner may delete.
     */
    public function destroy(Request $request, string $package): Response
    {
        $record = $this->find($request, $package);

        abort_unless(
            $this->token($request)->mayDeletePackage($record),
            403,
            'This token may not delete this package.',
        );

        $record->delete();

        return response()->noContent();
    }

    /**
     * The package this request addresses, or a 404 — which is also the answer
     * for one the token may not see.
     */
    private function find(Request $request, string $package): Package
    {
        $record = $this->packages($request)->whereKey($package)->first();

        abort_unless($record instanceof Package, 404, 'No such package.');

        return $record;
    }

    /**
     * The Composer repository a create is addressed to: the one mounted at the
     * given path, or the default repository when none is named.
     *
     * Resolved through the caller's own visibility so an unknown path and an
     * invisible one read alike. The default repository is exempt because it is
     * the registry root — its existence is not a secret, and whether this
     * token may write into it is the next line's question, not this one's.
     */
    private function targetRepository(Request $request): Repository
    {
        $request->validate(['repository' => ['nullable', 'string', 'max:64']]);

        $path = $request->string('repository')->toString();

        if ($path === '') {
            return Repository::default();
        }

        $repository = $this->repositories($request)->where('path', $path)->first();

        if (! $repository instanceof Repository) {
            throw ValidationException::withMessages([
                'repository' => "No Composer repository is served at /r/{$path}.",
            ]);
        }

        return $repository;
    }
}
