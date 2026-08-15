<?php

namespace App\Http\Controllers;

use App\Events\PackageDownloaded;
use App\Http\Middleware\ResolveComposerRepository;
use App\Models\MirroredArchive;
use App\Models\MirroredPackage;
use App\Models\Package;
use App\Models\PackageAdvisory;
use App\Models\PackageVersion;
use App\Models\Repository;
use App\Models\ReservedVendor;
use App\Models\Token;
use App\Services\ArchiveStore;
use App\Services\CreateVersionFromZip;
use App\Services\Mirror\MirrorService;
use App\Support\ComposerName;
use Carbon\CarbonImmutable;
use Composer\MetadataMinifier\MetadataMinifier;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
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
    private const PAYLOAD_REVISION = 4;

    /**
     * When PAYLOAD_REVISION last moved, which is the same fact said in the one
     * vocabulary a client actually speaks.
     *
     * The counter above only ever reached the ETag, and Composer's metadata
     * downloader sends `If-Modified-Since` and nothing else for `/p2` — so a
     * deploy that changed the rendering was invisible to every client that had
     * a copy, which is precisely the case the counter exists for. Folded into
     * Last-Modified as a floor, it says what the counter was always trying to:
     * anything you kept from before this date is not what this code sends now.
     *
     * Bumped with the revision, and with one other thing: the address this
     * registry answers on. The dist base is folded into the ETag and has
     * nowhere to go in a Last-Modified, so a registry that moves to a new
     * APP_URL is the one change of substance this date is the only way to
     * announce. Nothing else moves it — a date that drifted on its own would
     * invalidate the whole registry for nothing.
     */
    private const REVISION_EPOCH = '2026-08-10T00:00:00Z';

    public function __construct(
        private readonly ArchiveStore $archives,
        private readonly MirrorService $mirror,
    ) {}

    /**
     * The repository root that Composer fetches first.
     *
     * Conditional, because this is the one document Composer cannot lazily
     * skip: every `composer update` and every `composer install` fetches it,
     * and building it means asking which vendors this principal may be served
     * — a query over every visible package with a correlated existence check
     * per row, to produce about a kilobyte. A 304 skips all of it.
     *
     * Composer sends `If-Modified-Since` here, but only for a response that
     * carried Last-Modified, and getting that right for a *set* is the whole
     * difficulty. A set can shrink, and a validator cut from the rows would
     * then move backwards — which, compared with `>=`, is a client 304ing
     * forever on a document it may no longer be entitled to. So the fingerprint
     * below is what is compared, and the date served is only ever the moment
     * that fingerprint was first seen. It cannot move backwards by
     * construction, whatever the rows do.
     *
     * Private and Vary'd for the same reason /p2 is: what this URL answers
     * depends on who asked.
     */
    public function root(Request $request): Response
    {
        $repository = $this->repository($request);

        $response = response('', 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'private, no-cache',
            'Vary' => 'Authorization',
        ]);

        $state = $this->rootState($request, $repository);

        $response->setLastModified($state['changed']);
        $response->setEtag($state['fingerprint'], weak: true);

        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response->setContent(json_encode([
            'metadata-url' => $repository->pathPrefix().'/p2/%package%.json',
            'available-package-patterns' => $this->availablePackagePatterns($request, $state['fingerprint']),
            // Without this key Composer's auditor skips every package resolved
            // from here — silently, because "this repository publishes no
            // advisories" and "this repository was never asked" look identical
            // from the consumer's side. Since 2.9 an audit runs as part of
            // `composer update`, so private packages were the one part of a
            // dependency graph nobody was checking.
            //
            // `metadata: false` because the per-package alternative — inlining
            // a `security-advisories` key into each /p2 document — is only ever
            // read when the caller allows *partial* advisories, which is the
            // update-time summary audit and not `composer audit` itself. It
            // would also fold advisory changes into the /p2 payload cache and
            // its ETag, making a newly recorded advisory wait on a package
            // write to become visible. The api-url below answers both callers
            // from live rows instead.
            'security-advisories' => [
                'metadata' => false,
                'api-url' => $repository->url('/security-advisories'),
            ],
            'search' => $repository->url('/search.json').'?q=%query%&type=%type%',
            'list' => $repository->url('/list.json'),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * A cheap fingerprint of everything the root document is built from, and
     * the moment it last changed.
     *
     * The fingerprint is one aggregate over the same visibility-scoped query
     * that answers list.json — how many packages this principal is served, when
     * one of them last changed, and the highest id among them — plus the facts
     * about the repository itself that reach the body. It moves for every
     * change that matters and reads no rows to do it: a package published, a
     * package deleted, a rename, a grant given or taken away (all of which
     * change the *set*, so the count and the maxima move with them), the mount
     * moving, or upstreams being switched on.
     *
     * The date is not derived from the aggregate at all, and that is
     * deliberate. Every part of the aggregate can go *down* — a package deleted
     * takes the count and both maxima back to where they were — and a
     * Last-Modified that goes backwards is worse than none, because Symfony
     * compares it with `>=` and a client holding the later date is then told
     * "not modified" forever. So the fingerprint is remembered against the
     * moment it was first served, and that moment is what goes on the wire: it
     * only ever moves forward, whatever the rows did to get there.
     *
     * A cold cache answers `now`, which is later than anything already handed
     * out — so the worst a flushed store costs is one re-fetch per client.
     *
     * @return array{fingerprint: string, changed: CarbonImmutable}
     */
    private function rootState(Request $request, Repository $repository): array
    {
        $packages = (array) $this->servedPackages($request)
            ->toBase()
            ->selectRaw('count(*) as package_count, max(packages.updated_at) as changed_at, coalesce(max(packages.id), 0) as newest_id')
            ->first();

        $scope = $repository->getKey().':'.($this->token($request)?->getKey() ?? 'anonymous');

        $fingerprint = hash('xxh128', implode('|', [
            self::PAYLOAD_REVISION,
            $repository->url(),
            $repository->updated_at?->getTimestamp() ?? 0,
            // Whether the patterns are this registry's own vendors or the
            // universal one, which no count of packages would ever reveal.
            $repository->mirrors() ? 'mirror' : 'local',
            $scope,
            (int) ($packages['package_count'] ?? 0),
            (string) ($packages['changed_at'] ?? ''),
            (int) ($packages['newest_id'] ?? 0),
        ]));

        return ['fingerprint' => $fingerprint, 'changed' => $this->firstSeen($scope, $fingerprint)];
    }

    /**
     * When this principal's root document last became what it now is.
     *
     * Held per principal rather than per fingerprint, so that a set which
     * returns to a shape it held before — a package added and then removed
     * again, a grant given back — is dated by when it returned and not by when
     * it was last there. Dating it by the earlier visit would hand back a date
     * before one a client already holds, which is exactly the 304-forever this
     * exists to avoid.
     */
    private function firstSeen(string $scope, string $fingerprint): CarbonImmutable
    {
        $key = "composer:root:{$scope}";
        $seen = cache()->get($key);

        if (is_array($seen) && ($seen['fingerprint'] ?? null) === $fingerprint && is_string($seen['changed'] ?? null)) {
            return CarbonImmutable::parse($seen['changed']);
        }

        $changed = CarbonImmutable::now();

        cache()->put(
            $key,
            ['fingerprint' => $fingerprint, 'changed' => $changed->toIso8601String()],
            now()->addDays((int) config('registry.metadata_cache.days')),
        );

        return $changed;
    }

    /**
     * What this repository tells Composer it could possibly answer for.
     *
     * Without some statement of this, Composer has to assume the answer is
     * "anything" and asks about every package in the consuming project's
     * dependency graph — twice each, releases and branches — on every cold
     * update. A project with a few hundred transitive dependencies turns one
     * `composer update` into a few hundred authenticated 404s here, which
     * costs more than serving the real packages does. So a repository that
     * only publishes its own packages advertises its vendor prefixes and is
     * asked about nothing else.
     *
     * A mirroring repository has to say the opposite, and this is the single
     * most important interaction mirroring has with what was already here.
     * Left as it was, the list would name only the local vendors — which tells
     * Composer never to ask about `symfony/*`, and the mirror would then serve
     * nothing at all, silently and for exactly the packages it exists to
     * serve. There is no narrower true answer available either: what an
     * upstream has is not knowable without asking it, and the point of
     * on-demand caching is not to enumerate packagist.org first.
     *
     * So one universal pattern — any vendor, any name — stated rather than
     * achieved by omitting the key. Both make Composer ask about everything;
     * the pattern says it is a decision. What it costs is the 404 storm the
     * key was added to prevent — but those requests are now the mirror lookups
     * themselves, which is the feature rather than the waste, and each one is
     * answered from the cache or a cached absence rather than a database miss.
     *
     * Vendor patterns rather than `available-packages` in either case: an
     * inline list of every name would have to be rebuilt and re-sent on each
     * root fetch, and it is the one document Composer cannot lazily skip.
     *
     * Cached under the fingerprint the validators are cut from, on the same
     * discipline as the /p2 payload: the entry is superseded rather than
     * invalidated, so nothing that publishes, deletes or re-scopes a package
     * has to remember this cache exists. It is only reached at all when the
     * request was not already answered 304 above.
     *
     * @return list<string>
     */
    private function availablePackagePatterns(Request $request, string $fingerprint): array
    {
        if ($this->repository($request)->mirrors()) {
            return ['*/*'];
        }

        $key = "composer:patterns:{$fingerprint}";

        $cached = cache()->get($key);

        if (is_array($cached)) {
            return array_values(array_map(strval(...), $cached));
        }

        $patterns = $this->servedVendorPatterns($request);

        cache()->put($key, $patterns, now()->addDays((int) config('registry.metadata_cache.days')));

        return $patterns;
    }

    /**
     * The vendor prefixes this request's principal may be served, written as
     * Composer name patterns.
     *
     * Scoped through the same visibility every other endpoint uses, so two
     * tokens with different grants are told about different vendors and
     * neither hears of one it could not have fetched anyway. That is also why
     * this leaks nothing new: list.json already enumerates whole names to
     * exactly the principals who can reach this document, and a vendor prefix
     * is strictly less than a name.
     *
     * Split in PHP rather than by a SQL substring because "everything before
     * the first slash" is spelled differently on each of the three databases
     * this supports, and the set being split is the one list.json already
     * plucks whole.
     *
     * @return list<string>
     */
    private function servedVendorPatterns(Request $request): array
    {
        return $this->servedPackages($request)
            ->pluck('name')
            ->map(fn (string $name): string => Str::before($name, '/').'/*')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Search served packages by name prefix and optional Composer type,
     * mirroring packagist.org's search.json shape.
     *
     * Local packages only, even on a repository with upstreams. Search is a
     * menu — it answers "what is here that I could depend on" — and a mirror's
     * cache is not a catalogue of anything: it holds whichever transitive
     * dependencies some project happened to install, which is an accident of
     * traffic rather than a fact about the repository. Returning `symfony/*`
     * because somebody's build pulled it in last Tuesday, and not returning it
     * next month because retention swept it, would be worse than not
     * answering. Searching a repository for its own packages stays exactly as
     * useful as it was, and what is cached is an operational question, which
     * the admin panel answers.
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
     *
     * Local packages only, even when the repository mirrors — as with search
     * above. This answers "what does this registry publish", which stays a
     * true and stable statement; a mirror's cache is neither. Adding the names
     * that happen to be warm right now would produce a list that changes when
     * an unrelated project installs something, shrinks when `mirror:prune`
     * runs, and is never the set of names the repository can actually resolve,
     * which is all of them. Resolution does not depend on it either: the root
     * document's universal pattern is what tells Composer to ask.
     *
     * Composer narrows the list itself when it can — `composer search
     * --only-name acme/*` sends `?vendor=acme&filter=acme/*`, and its
     * getPackageNames() derives the vendor from a `vendor/*` filter. Both are
     * honoured because the reply is *not* filtered again at the client end when
     * it comes from this endpoint: an unfiltered answer is a wrong answer, not
     * merely a fat one.
     */
    public function list(Request $request): JsonResponse
    {
        $names = $this->servedPackages($request)
            ->when(
                $request->filled('vendor'),
                // `%` and `_` are literals here, as in search() — a vendor of
                // `%` narrows to nothing rather than to everything.
                fn (Builder $query) => $query->whereLike(
                    'name',
                    addcslashes($request->string('vendor')->toString(), '\\%_').'/%',
                ),
            )
            ->orderBy('name')
            ->pluck('name');

        $filter = $request->string('filter')->toString();

        if ($filter !== '') {
            // In PHP rather than in SQL: Composer's pattern is a regexp with
            // its own escaping and case-insensitivity, and the three databases
            // this app supports disagree about both in LIKE. The set being
            // filtered is one this endpoint already plucks whole.
            $expression = ComposerName::patternToRegexp($filter);

            $names = $names->filter(
                fn (string $name): bool => preg_match($expression, $name) === 1,
            )->values();
        }

        return response()->json(['packageNames' => $names]);
    }

    /**
     * Known vulnerabilities in the named packages — the endpoint `composer
     * audit` (and the audit `composer update` runs since 2.9) posts to.
     *
     * Composer sends `Content-type: application/x-www-form-urlencoded` with
     * `http_build_query(['packages' => [...]])` over POST, so the names arrive
     * as `packages[]`. GET is answered too because that is how packagist.org's
     * own advisory API is reached, and because a POST-only endpoint cannot be
     * checked with a browser or a plain curl when an audit comes back empty
     * and someone has to find out which side is wrong.
     *
     * A package the caller may not see must not appear here even as an empty
     * entry: presence in the response is precisely how Composer learns a
     * repository knows a name, and answering for a private package would leak
     * its existence to a token that cannot fetch it.
     *
     * Both halves of the contract are read from Composer's own
     * Repository\ComposerRepository::getSecurityAdvisories(), which is where
     * the request is built and the response parsed. Named rather than linked
     * because composer/composer is not a dependency of this app.
     *
     * A mirroring repository passes the names it does not publish through to
     * its upstreams, and that is a deliberate decision rather than a
     * convenience. Since 2.9 an audit runs inside every `composer update`, so
     * a project that resolves its whole graph through this registry would
     * otherwise have auditing quietly switched off for the mirrored majority
     * of it — and "this repository reported nothing" is indistinguishable from
     * "nobody checked" at the consumer's end. Mirroring must not make a
     * project less safe than pointing at packagist.org directly did.
     *
     * This is not advisory ingestion, which is a different thing and not built
     * here: nothing is imported, stored or reconciled, and no external feed is
     * consulted. It is the same endpoint this app already implements, asked of
     * the repository the packages themselves came from, and only for names
     * this registry does not answer for itself.
     */
    public function securityAdvisories(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Bounded because this list is the request: a name here costs a
            // row in an `in (…)` and, for a mirroring repository, a place in
            // an upstream POST. No `composer.lock` in existence names two
            // thousand packages — Composer sends the installed set, and the
            // largest applications are well under half of that — so this
            // refuses nothing anybody has, and stops one read token asking
            // about a hundred thousand names at a time.
            'packages' => ['array', 'max:2000'],
            'packages.*' => ['string', 'max:255'],
        ]);

        /** @var list<string> $requested */
        $requested = array_values(array_unique($validated['packages'] ?? []));

        // Composer already narrows this list to the vendor patterns the root
        // advertised, and those are cut from the very packages queried below —
        // so a well-behaved client asks about a bounded set. This is still
        // written to survive any list: one query, whatever its length.
        $packages = $requested === [] ? new EloquentCollection : $this->servedPackages($request)
            ->whereIn('name', array_map(mb_strtolower(...), $requested))
            ->with('advisories')
            ->get();

        $repository = $this->repository($request);

        // What the upstreams say about the names this registry does not
        // publish. Asked for the whole requested set at once — the service
        // drops the ones it may not mirror, which includes every name answered
        // locally below, so no package is ever asked about twice.
        $mirrored = $this->mayReadMirrored($request, $repository)
            ? $this->mirror->advisories($repository, array_map(mb_strtolower(...), $requested))
            : [];

        $advisories = [];

        foreach ($requested as $name) {
            $package = $packages->first(
                fn (Package $package): bool => mb_strtolower((string) $package->name) === mb_strtolower($name),
            );

            if (! $package instanceof Package) {
                $upstream = $mirrored[mb_strtolower($name)] ?? null;

                if (is_array($upstream)) {
                    $advisories[$name] = $upstream;
                }

                continue;
            }

            // Keyed by the spelling that was asked for, not the stored one.
            // Composer looks each returned name up in the map it built from
            // the installed packages, warns about anything it did not request,
            // and drops it — so a case difference between the two would throw
            // away the advisory it just fetched.
            //
            // A clean package is reported as an empty list rather than
            // omitted, which is what tells Composer this repository covers the
            // name and had nothing to report. Omitting it reads as "unknown
            // here", and Composer then has to keep looking elsewhere.
            $advisories[$name] = $package->advisories
                ->map(fn (PackageAdvisory $advisory): array => $advisory->toComposerAdvisory((string) $package->name, $repository))
                ->values();
        }

        return response()->json([
            // Cast so an empty result serialises as `{}` and not `[]`:
            // Composer iterates this as a map of package name to advisory
            // list, and a JSON array is not one.
            'advisories' => (object) $advisories,
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
     * `list.json` and `search.json` deliberately get none. Both answer a *set*
     * of packages chosen by the caller's grants, and for them the query that
     * would fingerprint the set is the query that answers them — a 304 would
     * skip nothing. Neither is on a hot path either: an ordinary resolve goes
     * through metadata-url, and these are reached by a wildcard requirement or
     * a `composer show`.
     *
     * `packages.json` used to be in that list, on the grounds that it was three
     * URL templates built from a row already in memory. It has not been that
     * since it began advertising vendor patterns, and it is the one document
     * Composer always fetches; it now carries validators of its own.
     */
    public function metadata(Request $request, string $vendor, string $package): Response
    {
        $repository = $this->repository($request);

        $dev = str_ends_with($package, '~dev');

        // Lowercased because that is how the column is stored (see
        // Package::normalizeName) and because the comparison below is an
        // equality on it. Composer already asks in lowercase, so this changes
        // nothing for the client that matters; what it buys is that a hand-typed
        // URL, a browser check or a non-Composer client resolves the same
        // package, on every engine, instead of only on MySQL's collation.
        // Folding the input rather than the column keeps the lookup on the
        // index — this is the one endpoint a `composer update` hammers.
        $name = mb_strtolower("{$vendor}/".($dev ? substr($package, 0, -4) : $package));

        $record = $repository->packages()
            ->visibleTo($this->token($request))
            ->where('name', $name)
            ->first();

        // Visibility is settled before a validator is so much as computed, so
        // a client that may not see this package gets the 404 it always got
        // and never a 304 confirming the package is here.
        //
        // Only a repository with no local answer falls through to its
        // upstreams, and the mirror refuses again on its own terms — a name
        // published anywhere in this installation is never served from
        // somebody else's copy, whether or not this caller could have seen the
        // local one. So an invisible local package still 404s, and 404s
        // without an upstream having been asked anything.
        if (! $record instanceof Package) {
            return $this->mirroredMetadata($request, $repository, $name, $dev);
        }

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

        $response->setLastModified($this->lastModified($repository, $record, $state));
        $response->setEtag($etag, weak: true);

        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response->setContent($this->payload($repository, $record, $dev, $etag));
    }

    /**
     * A package this repository does not publish, served from an upstream.
     *
     * Conditional in the same shape as the local endpoint above, on validators
     * that have nothing to do with the ones above. A mirrored document has no
     * version rows to fingerprint — the whole of what is held is a blob of
     * somebody else's bytes — so the ETag is cut from a digest of those bytes
     * and Last-Modified is when they last changed, which is not the same as
     * when they were last confirmed. See MirrorService::etag().
     *
     * @see MirrorService for the rules on which names may be answered here
     */
    private function mirroredMetadata(Request $request, Repository $repository, string $name, bool $dev): Response
    {
        $mirrored = $this->mayReadMirrored($request, $repository)
            ? $this->mirror->metadata($repository, mb_strtolower($name), $dev)
            : null;

        // Word for word what a package that is simply not here says, because
        // that is what this is. Nothing about the response distinguishes "no
        // upstream has it", "mirroring is off" and "the vendor is reserved".
        abort_unless($mirrored instanceof MirroredPackage, 404, "Package {$name} is not served by this repository.");

        $response = response('', 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'private, no-cache',
            'Vary' => 'Authorization',
        ]);

        $response->setLastModified($this->mirror->lastModified($repository, $mirrored));
        $response->setEtag($this->mirror->etag($repository, $mirrored), weak: true);

        if ($response->isNotModified($request)) {
            return $response;
        }

        return $response->setContent($this->mirror->render($repository, $mirrored));
    }

    /**
     * Whether this request's principal may be served mirrored content at all.
     *
     * Mirrored packages have no rows of their own to scope, so the repository
     * is the unit: a principal that may read this repository may read what
     * this repository mirrors, and one that may not gets nothing. That is the
     * only coherent reading — a grant names a package this registry publishes,
     * and there is no such package here to name.
     *
     * It is checked explicitly rather than left to the authentication
     * middleware, which only asks whether the credential is live and whether
     * the repository is public. A token scoped to another repository passes
     * that and is then narrowed to nothing by per-package visibility — a
     * narrowing mirrored content is not subject to. Without this, such a token
     * could pull a private upstream's packages through a mount it was never
     * granted.
     *
     * The known width of it, stated because it is a choice and not an
     * accident: a grant on a *single package* makes its repository visible
     * (see Repository::scopeVisibleTo), so such a token reaches that
     * repository's whole mirror. That is what the feature is for — the reason
     * to grant a build token a package is so it can install that package and
     * its transitive dependencies, which are precisely the mirrored ones — but
     * it means an upstream's content is only ever as private as the least
     * privileged principal who can read anything in the repository.
     *
     * @see docs/mirroring.md#access-control
     */
    private function mayReadMirrored(Request $request, Repository $repository): bool
    {
        return Repository::query()
            ->whereKey($repository->getKey())
            ->visibleTo($this->token($request))
            ->exists();
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
     * leaves no timestamp behind: without it the aggregate goes backwards when
     * a version goes, and since Symfony compares If-Modified-Since with `>=` a
     * client holding the older date would 304 forever — never learning the
     * version was withdrawn, and never self-correcting, because a 304 carries
     * no Last-Modified of its own to replace what it kept.
     *
     * That only works because the package's timestamp is guaranteed to move
     * whenever its contents do, which is PackageVersion::touchPackage's job
     * rather than something each removal path is trusted to remember — the
     * panel's version delete and a discovery that prunes and then throws both
     * used to get it wrong.
     *
     * The repository's own timestamp is in the max for the one thing neither
     * of the others can express: the dist URLs are baked into the body, and a
     * repository moved to a different mount serves different bytes from
     * identical rows. The ETag catches that through the base it folds in;
     * Last-Modified has nowhere to put a URL, so it takes the moment the mount
     * last changed instead. Without it a client keeps following dist URLs to a
     * path that stopped existing.
     *
     * @param  array{count: int, changed: ?CarbonImmutable, newest: int}  $state
     */
    private function lastModified(Repository $repository, Package $package, array $state): DateTimeInterface
    {
        $timestamps = array_filter([
            CarbonImmutable::parse(self::REVISION_EPOCH),
            $package->updated_at,
            $repository->updated_at,
            $state['changed'],
        ]);

        return max($timestamps);
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
     * So is the package's own timestamp, because the version rows are not the
     * only thing the body is rendered from: the name it is keyed by and the
     * dist URLs built from that name both live on the package.
     *
     * The name and the abandonment notice are then folded in *by value* rather
     * than left to that timestamp. Timestamps are second-resolution, so a write
     * landing in the same second as the one before it hashes identically — and
     * the payload cache holds entries for days, so "this package is now called
     * something else" or "stop using this" would go unsaid for a week. Neither
     * is hypothetical for the name: resolveComposerName() renames a package
     * during its first sync, in the same run that writes its version rows.
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
            $package->updated_at?->getTimestamp() ?? 0,
            (string) $package->name,
            var_export($package->abandonment(), true),
            $dev ? 'dev' : 'stable',
            $state['count'],
            $state['changed']?->getTimestamp() ?? 0,
            $state['newest'],
        ]));
    }

    /**
     * The `/p2` document for one flavour of one package, as the bytes to send.
     *
     * Served in Packagist's minified form, where each version carries only
     * what differs from the one before it and Composer expands the chain back
     * out on arrival — the same fields repeated down every version of a
     * package are most of what a `/p2` document weighs. The ordering the query
     * already applies is what makes that pay: newest first puts the versions
     * most alike next to each other, and the first entry — the one Composer
     * most often wants — is the complete one.
     *
     * Minifying with Composer's own library rather than by hand is the point:
     * minify() and the expand() every Composer client runs are inverses by
     * construction, instead of by our reading of an undocumented format.
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

        // Composer reads abandonment off the version it resolved, not off the
        // package, so it belongs in every entry. The minifier then collapses
        // the repetition back down to the first one.
        $abandonment = $package->abandonment();

        $versions = $package->versions()
            ->where('is_dev', $dev)
            // Releases sort by the normalizer's order string, whose lexical
            // order is semantic order (1.10.0 above 1.9.0). Branches have no
            // release line to sort along, so dev versions keep name order.
            ->when($dev, fn (Builder $query) => $query->orderByDesc('version'))
            ->unless($dev, fn (Builder $query) => $query->orderedByVersion())
            ->get()
            ->map(fn (PackageVersion $version): array => [
                ...$version->metadata,
                'name' => $name,
                ...($abandonment !== null ? ['abandoned' => $abandonment] : []),
                // Composer reads release dates from `time`. The column is the
                // source of truth, so the served date can never drift from it;
                // a version synced before the date was tracked omits the key
                // rather than advertising a null.
                //
                // Rendered in UTC explicitly, not in whatever APP_TIMEZONE
                // happens to be. The instant is the same either way, but the
                // *bytes* are not — and nothing in the validators is derived
                // from the bytes, so changing that setting would silently
                // rewrite every document in the registry while every client
                // went on 304ing against the copy it already had.
                ...($version->released_at ? ['time' => $version->released_at->utc()->toIso8601String()] : []),
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

        return json_encode([
            // A document without this key is read as already expanded, which
            // is what every response here was until now.
            'minified' => 'composer/2.0',
            'packages' => [$name => MetadataMinifier::minify($versions)],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Serve a version's stored archive from the dist disk.
     *
     * Archives are built at sync time, so serving never reaches for GitHub —
     * a consumer needs no GitHub credentials, and an archive that was never
     * stored (or has gone missing) is a 404 the next sync repairs.
     *
     * The bytes only pass through PHP when the disk has no URL of its own to
     * offer, which in practice means a single-server install on the local
     * disk. See the redirect below for why.
     */
    public function dist(Request $request, string $vendor, string $package, string $reference): StreamedResponse|RedirectResponse
    {
        // Lowercased for the same reason as the metadata endpoint above: the
        // stored name is canonical, so the spelling in the URL is folded to
        // meet it rather than the column being folded to meet the URL.
        $name = mb_strtolower("{$vendor}/{$package}");

        $repository = $this->repository($request);

        $record = $repository->packages()
            ->visibleTo($this->token($request))
            ->where('name', $name)
            ->first();

        if (! $record instanceof Package) {
            return $this->mirroredDist($request, $repository, $name, $reference);
        }

        $versions = $record->versions()->where('reference', $reference)->get();

        abort_if($versions->isEmpty(), 404, "Reference {$reference} is not a known version of {$name}.");

        $disk = $this->archives->disk();

        // A tag and a branch can share a commit; any row with a stored
        // archive serves for both. Rows that never got one are dropped before
        // the disk is asked anything, and the search stops at the first live
        // path — so an ordinary download costs one existence check, and only a
        // row whose file has actually gone missing costs a second.
        //
        // That check is the reason this endpoint answers 404 instead of
        // handing out a link to nothing: a row's path can outlive its file
        // (storage lost on a redeploy, an object deleted out from under us),
        // and a sibling row may still hold a live zip for the same commit.
        // Keeping it costs one metadata call against a transfer that is orders
        // of magnitude larger, whichever way the archive is then served.
        $version = $versions
            ->filter(fn (PackageVersion $version): bool => $version->archive_path !== null)
            ->first(fn (PackageVersion $version): bool => $disk->exists($version->archive_path));

        abort_unless(
            $version instanceof PackageVersion,
            404,
            "No archive is stored for {$name}@{$reference}; syncing the package will build it.",
        );

        // Only an archive actually being served counts; every 404 above
        // returned before reaching this line. Both ways of serving one are
        // below, and a request takes exactly one of them.
        //
        // A HEAD is not one of them. Laravel answers HEAD on every GET route
        // and the body is dropped far below this method, so a `curl -I`, an
        // uptime check or a proxy probing the URL would otherwise land in
        // total_downloads as an install that took no bytes.
        if ($request->isMethod('GET')) {
            PackageDownloaded::dispatch(
                $record->id,
                $version->id,
                $version->version,
                $this->token($request)?->token_prefix,
            );
        }

        // Reading the zip out through PHP pins a worker for the length of the
        // transfer, and one `composer install` fetches an archive per package.
        // A disk that can issue its own URLs is handed the transfer instead:
        // Composer follows the redirect and still verifies what arrives
        // against the `shasum` /p2 published, so integrity never rested on the
        // bytes having come from here.
        //
        // The URL is a bearer credential for this one object for as long as it
        // lives — it carries the storage service's signature, not ours, so
        // none of this app's tokens, repository scoping or package visibility
        // reaches it. That is acceptable only in this shape: it is minted
        // after the visibility check above has already passed, for the single
        // archive this request was entitled to, and it expires minutes later
        // (see ArchiveStore for the window). Anything that mints one earlier,
        // or for a path the caller has not been cleared for, gives that check
        // away.
        $url = $this->archives->temporaryUrl($version->archive_path);

        if ($url !== null) {
            // The archive is immutable; this response is not. It carries a URL
            // that stops working in minutes, and a cache replaying it after
            // that turns a redirect into a failed install. What may be done
            // with the bytes on the other side is the bucket's headers to say.
            return redirect()->away($url, headers: ['Cache-Control' => 'no-store']);
        }

        return $disk->download($version->archive_path, ArchiveStore::downloadFilename($name, $version->version), [
            'Content-Type' => 'application/zip',
            // The URL names a commit, so it can only ever answer with these
            // bytes: there is nothing for a client to revalidate, and a year
            // is the conventional way to spell "as long as you like".
            //
            // `private` because whether these bytes may be handed over depends
            // on the token that asked, which is a decision a shared cache is
            // not party to. A client's own cache is different in kind: it can
            // only replay an archive that client already downloaded, so a
            // token revoked afterwards loses it nothing it did not have.
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    /**
     * An upstream release archive, served from this registry's own disk.
     *
     * The first request for one fetches it, checks it against the sha1 the
     * upstream published, and stores it; every request afterwards is answered
     * from the dist disk and touches nothing outside this installation. That
     * is the whole promise of mirroring the archives as well as the metadata —
     * without it a mirrored `composer install` still depends on GitHub being
     * up, and Composer would be fetching from codeload with the consumer's own
     * credentials or none at all.
     *
     * Downloads are not counted. `total_downloads` is a statement about
     * packages this registry publishes — it drives the panel's charts, the
     * package table and search ordering — and folding somebody else's release
     * into it would answer a question nobody asked with a number nobody can
     * act on.
     */
    private function mirroredDist(Request $request, Repository $repository, string $name, string $reference): StreamedResponse|RedirectResponse
    {
        $archive = $this->mayReadMirrored($request, $repository)
            ? $this->mirror->archive($repository, mb_strtolower($name), $reference)
            : null;

        abort_unless(
            $archive instanceof MirroredArchive,
            404,
            "No archive is stored for {$name}@{$reference}, and none could be mirrored.",
        );

        $path = (string) $archive->path;

        // Both ways of serving an archive, chosen exactly as they are for a
        // published one; see dist() above for why a signing disk is handed the
        // transfer and why the redirect must not be cached.
        $url = $this->archives->temporaryUrl($path);

        if ($url !== null) {
            return redirect()->away($url, headers: ['Cache-Control' => 'no-store']);
        }

        // Named by reference rather than by version, unlike a published
        // archive: a mirrored one is addressed by the upstream's commit and
        // this registry holds no version row to look a tag up in.
        return $this->archives->disk()->download($path, ArchiveStore::downloadFilename($name, $reference), [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, max-age=31536000, immutable',
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

        $existing = $repository->packages()->where('name', $name)->first();

        abort_unless(
            $this->mayUploadTo($request, $repository, $existing),
            403,
            'This token may not publish into this repository.',
        );

        // Only for a name this repository does not serve yet. A reservation
        // governs what may be *introduced* under a vendor; a package that
        // predates one keeps publishing, exactly as the model's own guard has
        // it, because breaking a running pipeline is not what protecting a
        // namespace is supposed to cost.
        if (! $existing instanceof Package) {
            $conflict = ReservedVendor::conflictFor($name, (int) $repository->id);

            abort_if($conflict instanceof ReservedVendor, 403, $conflict?->refusal($name) ?? '');
        }

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
     * Whether the authenticated principal's scope reaches this publish.
     *
     * Replacing a version of a package that already exists is a write to that
     * package, which a per-package grant reaches; publishing a name nothing
     * here serves yet is a write to the repository, which it does not.
     *
     * @see Token::mayWriteTo() where both rules live
     */
    private function mayUploadTo(Request $request, Repository $repository, ?Package $existing): bool
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
