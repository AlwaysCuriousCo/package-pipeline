<?php

namespace App\Services\Mirror;

use App\Models\MirroredArchive;
use App\Models\MirroredPackage;
use App\Models\Package;
use App\Models\Repository;
use App\Models\ReservedVendor;
use App\Models\Upstream;
use App\Services\ArchiveStore;
use App\Support\BoundedSink;
use App\Support\HttpTimeouts;
use Carbon\CarbonImmutable;
use Composer\MetadataMinifier\MetadataMinifier;
use DateTimeInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Serves packages this registry does not publish, from a repository's
 * upstreams, out of a cache it fills on demand.
 *
 * The one rule everything here is arranged around: **a local package always
 * wins, unconditionally**. Not "wins when it has a higher version", not "wins
 * when it is visible to the caller" — a name this installation publishes, or
 * has reserved the vendor of, is never answered from somebody else's copy.
 * That is the defence against dependency confusion, and it is only a defence
 * if it has no exceptions to reason about. See mayMirror() for the whole of
 * it, and docs/dependency-confusion.md for what the consuming project still
 * has to do.
 *
 * Nothing here runs at all for a repository with no upstreams, which is every
 * repository until an operator adds one.
 *
 * @see docs/mirroring.md
 */
class MirrorService
{
    /**
     * Bumped whenever the *rewriting* below changes shape.
     *
     * A mirrored response's validator is cut from the upstream bytes plus this
     * number, so nothing else would tell a client whose copy predates a deploy
     * that what this code now sends is different.
     */
    private const PAYLOAD_REVISION = 2;

    /**
     * When PAYLOAD_REVISION last moved, for the clients that never see it.
     *
     * Composer's metadata downloader sends `If-Modified-Since` for `/p2` and
     * nothing else, so a number that only ever reached the ETag reached no
     * Composer at all — a deploy that changed the rewriting left every client
     * holding a copy of the old shape and revalidating happily against it.
     * Folded into Last-Modified as a floor, it says the same thing in the
     * vocabulary that is actually spoken. Bumped with the revision, never on
     * its own.
     */
    private const REVISION_EPOCH = '2026-08-10T00:00:00Z';

    /**
     * Composer's own package name grammar, from ValidatingArrayLoader.
     *
     * Applied before a name is put in a URL, a query or a file path, which
     * makes it the one guard several things rest on: a `vendor` of `..` cannot
     * reach out of the archive prefix, and a name Composer could never require
     * never becomes an outbound request.
     *
     * `D` is load-bearing rather than tidy. Without it `$` also matches before
     * a final newline, and a route parameter arrives rawurldecoded — so
     * `/p2/acme/private%0A.json` would be a name that passes this pattern,
     * fails to equal the `acme/private` this installation publishes, and is
     * therefore fetched from an upstream. `u` makes the same refusal explicit
     * for a subject that is not valid UTF-8, which preg_match answers `false`
     * for rather than `0`.
     */
    private const NAME_PATTERN = '/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/uD';

    /**
     * What may stand between `/dist/vendor/name/` and `.zip`.
     *
     * A dist reference is a commit sha everywhere it matters, but it is a
     * string the upstream chose, and it becomes both a URL segment and a path
     * on the dist disk. Slashes are excluded, which is what keeps it one of
     * each, and `D` — as on the name above — is what stops a trailing newline
     * riding along into either.
     */
    private const REFERENCE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$/D';

    /**
     * How long a held fetch lock survives the process holding it.
     *
     * Not a timeout on the work — it is the crash recovery, and so it is sized
     * past the longest the work can legitimately take: the API budget for a
     * metadata revalidation, the archive budget for a download. Shorter and a
     * slow but healthy transfer would have its lock expire underneath it,
     * which is the stampede this exists to prevent, arriving late.
     */
    private const REFRESH_LOCK_SECONDS = HttpTimeouts::API + HttpTimeouts::CONNECT + 30;

    private const ARCHIVE_LOCK_SECONDS = HttpTimeouts::ARCHIVE + HttpTimeouts::CONNECT + 30;

    public function __construct(
        private readonly ArchiveStore $archives,
        private readonly EgressPolicy $egress,
    ) {}

    private function client(Upstream $upstream): UpstreamClient
    {
        return new UpstreamClient($upstream, $this->egress);
    }

    /**
     * Whether this repository may answer for this name out of an upstream.
     */
    public function mayMirror(Repository $repository, string $name): bool
    {
        return $this->mirrorable($repository, [$name]) !== [];
    }

    /**
     * Which of these names this repository may answer for out of an upstream.
     *
     * Three refusals, in the order they are cheapest to decide:
     *
     * 1. No upstreams — mirroring is off, which is every installation until
     *    somebody turns it on.
     * 2. Not a Composer package name. Nothing else here has to wonder.
     * 3. A name this installation already publishes, or whose vendor it has
     *    reserved. This is the important one, and it is deliberately blunt.
     *    It does not ask which repository publishes the name, or whether the
     *    caller may see it: a package that exists here is never shadowed by an
     *    upstream's package of the same name, and a package that *cannot* be
     *    seen 404s rather than being answered from packagist.org — which,
     *    since the name is private, is where an attacker would have put one.
     *    A reserved vendor extends the same refusal to names not published
     *    yet, which is the case the reservation was always for.
     *
     * Answered for a whole set at a time, and in a fixed two queries, because
     * `composer audit` posts the entire installed set in one request: asking
     * per name would put a few hundred round trips in front of an audit just
     * to decide which names could be asked about at all.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public function mirrorable(Repository $repository, array $names): array
    {
        if (! $repository->mirrors()) {
            return [];
        }

        $candidates = array_values(array_unique(array_filter(
            $names,
            fn (string $name): bool => preg_match(self::NAME_PATTERN, $name) === 1,
        )));

        if ($candidates === []) {
            return [];
        }

        // Compared on `lower(name)`, not on the column, even though the column
        // is now canonical: Package::normalizeName() lowercases every name on
        // the way in, so an equality would match today on every engine.
        //
        // It stays case-insensitive because this is the dependency-confusion
        // guard, and the one property that makes it a guard is that it has no
        // precondition to reason about. An equality would silently inherit one
        // — that nothing has ever written this column outside the model. Two
        // things already do: `saveQuietly()` suppresses the saving hook
        // wholesale, and any future backfill migration or raw update is one
        // line away from doing the same. Missing a published package here is
        // the single mistake that matters, because it is what would let an
        // upstream answer for a private name; paying for that certainty is not
        // a trade worth making on the strength of an invariant maintained in
        // another file.
        //
        // Index-assisted without giving any of that up: there is an index over
        // `lower(name)` itself, so the comparison stays exactly what it was and
        // stops being a scan. It has to, because this runs on the metadata
        // request *and* the dist request for every mirrored dependency — a cold
        // install of three hundred packages is six hundred of these.
        $vendorNames = array_map(fn (string $name): string => mb_strtolower($name), $candidates);

        $published = Package::query()
            ->whereIn(DB::raw('lower(name)'), $vendorNames)
            ->pluck('name')
            // Not `mb_strtolower(...)` as a callable: Collection::map hands the
            // key to the callback as a second argument, where it lands in the
            // encoding parameter.
            ->map(fn (string $name): string => mb_strtolower($name))
            ->flip();

        $reserved = ReservedVendor::query()
            ->whereIn('vendor', array_map(ReservedVendor::normalize(...), $candidates))
            ->pluck('vendor')
            ->flip();

        return array_values(array_filter(
            $candidates,
            fn (string $name): bool => ! $published->has($name)
                && ! $reserved->has(ReservedVendor::normalize($name)),
        ));
    }

    /**
     * The cached upstream document to serve for one package, fetching or
     * revalidating it if need be, or null when no upstream has it.
     *
     * Upstreams are consulted in the operator's order and the first that has
     * the package wins — including over a *later* upstream that might have a
     * higher version, which is the same canonical-first-match rule Composer
     * applies to its own repository list.
     */
    public function metadata(Repository $repository, string $name, bool $dev): ?MirroredPackage
    {
        if (! $this->mayMirror($repository, $name)) {
            return null;
        }

        foreach ($repository->activeUpstreams() as $upstream) {
            $mirrored = $this->resolve($upstream, $name, $dev);

            if ($mirrored instanceof MirroredPackage && $mirrored->found()) {
                $mirrored->markUsed();

                return $mirrored;
            }
        }

        return null;
    }

    /**
     * A validator for a mirrored metadata response.
     *
     * Cut from a digest of the upstream bytes rather than from when they were
     * fetched, and that distinction is the whole design: revalidating a
     * package the upstream has not touched moves `fetched_at` every time the
     * TTL lapses, and an ETag built from that would tell every client its copy
     * was stale each hour for a document that never changed.
     *
     * Nothing here is derived from version rows, because there are none — the
     * local endpoint's fingerprint of counts, timestamps and ids has nothing
     * to count. It cannot be borrowed, and borrowing it would have meant an
     * aggregate over an empty set answering identically for every mirrored
     * package in the registry.
     *
     * The dist base is folded in for the same reason as the local one: the
     * URLs it builds are baked into the body being served, so a repository
     * moved to another mount serves different bytes from the same cache row.
     */
    /**
     * When a mirrored response last changed, as a client is told it.
     *
     * The upstream document's own `changed_at` is the substance of it, floored
     * by the deploy that last changed how this app rewrites one and raised by
     * the repository's own timestamp — the dist URLs written into the body come
     * from the mount, and a repository moved to another path serves different
     * bytes from an unchanged cache row.
     */
    public function lastModified(Repository $repository, MirroredPackage $mirrored): DateTimeInterface
    {
        return max(array_filter([
            CarbonImmutable::parse(self::REVISION_EPOCH),
            $mirrored->changed_at,
            $repository->updated_at,
        ]));
    }

    public function etag(Repository $repository, MirroredPackage $mirrored): string
    {
        return hash('xxh128', implode('|', [
            self::PAYLOAD_REVISION,
            $repository->url('/dist/'),
            $mirrored->name,
            $mirrored->is_dev ? 'dev' : 'stable',
            (string) $mirrored->digest,
        ]));
    }

    /**
     * The bytes to serve for one mirrored package.
     *
     * Rendered at most once per change, into the same cache the local endpoint
     * uses and under the same discipline: the key carries the validator, so an
     * entry is superseded rather than invalidated and no write path has to
     * remember to bust it.
     */
    public function render(Repository $repository, MirroredPackage $mirrored): string
    {
        $key = "composer:mirror:{$mirrored->getKey()}:".$this->etag($repository, $mirrored);

        $cached = cache()->get($key);

        if (is_string($cached)) {
            return $cached;
        }

        $json = $this->rewrite($repository, $mirrored);

        $ceiling = (int) config('registry.metadata_cache.max_kilobytes') * 1024;

        if (strlen($json) <= $ceiling) {
            cache()->put($key, $json, now()->addDays((int) config('registry.metadata_cache.days')));
        }

        return $json;
    }

    /**
     * The stored archive for one mirrored reference, fetching and verifying it
     * the first time, or null when this registry will not serve one.
     *
     * Null covers every refusal — an unmirrorable name, an upstream that has
     * no such reference, a download that failed, a size over the ceiling, and
     * a sha1 that did not match. All of them become the same 404, because they
     * are the same thing from the consumer's side: this registry has no
     * archive it is prepared to stand behind.
     */
    public function archive(Repository $repository, string $name, string $reference): ?MirroredArchive
    {
        if (! $this->mayMirror($repository, $name) || preg_match(self::REFERENCE_PATTERN, $reference) !== 1) {
            return null;
        }

        $upstreams = $repository->activeUpstreams();

        $stored = $this->storedArchive($upstreams, $name, $reference);

        if ($stored instanceof MirroredArchive) {
            return $stored;
        }

        // One fetch of one archive at a time, across the whole installation.
        // The uncached case is not the rare one — it is a CI fleet starting
        // fifty builds of the same commit at once, and without this each of
        // them downloads the same twenty megabytes into its own temporary file
        // and holds a PHP worker open for as long as it takes. The winner's
        // bytes serve all of them.
        $lock = Cache::lock("mirror:archive:{$name}:{$reference}", self::ARCHIVE_LOCK_SECONDS);

        try {
            return $lock->block(
                $this->lockWait(),
                // Re-read inside the lock: whoever held it was fetching this
                // exact archive, so the ordinary outcome of having waited is
                // that there is now nothing to fetch.
                fn (): ?MirroredArchive => $this->storedArchive($upstreams, $name, $reference)
                    ?? $this->fetchFromUpstreams($upstreams, $name, $reference),
            );
        } catch (LockTimeoutException) {
            // Somebody is still downloading it. Doing the work twice is waste;
            // answering 404 for a package a build needs is a broken install,
            // and there is no third option that is not one of those two.
            return $this->fetchFromUpstreams($upstreams, $name, $reference);
        }
    }

    /**
     * The archive already held for this reference, or null.
     *
     * @param  Collection<int, Upstream>  $upstreams
     */
    private function storedArchive(Collection $upstreams, string $name, string $reference): ?MirroredArchive
    {
        $existing = MirroredArchive::query()
            ->whereIn('upstream_id', $upstreams->modelKeys())
            ->where('name', $name)
            ->where('reference', $reference)
            ->first();

        if (! $existing instanceof MirroredArchive) {
            return null;
        }

        if ($this->archives->disk()->exists((string) $existing->path)) {
            $existing->markUsed();

            return $existing;
        }

        // The row outlived its file. Unlike a published version — where the
        // same situation needs a sync, a provider credential and a rebuild — a
        // mirror can always repair itself, because the upstream is still
        // holding the bytes.
        $existing->delete();

        return null;
    }

    /**
     * The first upstream that has this reference, fetched and stored.
     *
     * @param  Collection<int, Upstream>  $upstreams
     */
    private function fetchFromUpstreams(Collection $upstreams, string $name, string $reference): ?MirroredArchive
    {
        foreach ($upstreams as $upstream) {
            $dist = $this->distFor($upstream, $name, $reference);

            if ($dist === null) {
                continue;
            }

            $archive = $this->fetchArchive($upstream, $name, $reference, $dist);

            if ($archive instanceof MirroredArchive) {
                return $archive;
            }
        }

        return null;
    }

    /**
     * What the upstreams know about vulnerabilities in the named packages.
     *
     * This is a passthrough, not an advisory database: the same endpoint this
     * app already implements, asked of the repository the packages themselves
     * came from. Without it, mirroring would silently switch `composer audit`
     * off for most of a project's dependency graph — the packages resolved
     * from here would simply not be covered by anybody, and "no advisories"
     * and "never asked" look identical to the consumer.
     *
     * Cached briefly. An audit runs on every `composer update`, so a CI fleet
     * would otherwise put one upstream request per build in front of the
     * upstream; a few minutes of staleness on a feed that is itself hours old
     * buys that back. Never throws: a failed audit must not fail an install.
     *
     * @param  list<string>  $names
     * @return array<string, list<mixed>>
     */
    public function advisories(Repository $repository, array $names): array
    {
        $wanted = $this->mirrorable($repository, $names);

        if ($wanted === []) {
            return [];
        }

        $found = [];

        // Composer allows the request this is answering ten seconds, so the
        // upstreams are asked until that is spent rather than until they run
        // out: one upstream's worst case is the connection plus the read, and
        // after that there is nothing left to ask a second one with. What a
        // caller gets then is the coverage of the upstreams that did answer,
        // which is what it would have got from an upstream that had nothing.
        $deadline = microtime(true) + HttpTimeouts::ADVISORY * 2;

        foreach ($repository->activeUpstreams() as $upstream) {
            $outstanding = array_values(array_diff($wanted, array_keys($found)));

            if ($outstanding === [] || microtime(true) >= $deadline) {
                break;
            }

            $found += $this->upstreamAdvisories($upstream, $outstanding);
        }

        return $found;
    }

    /**
     * This upstream's answer for the named packages, from the cache where it
     * has one and from the upstream for the rest.
     *
     * @param  list<string>  $names
     * @return array<string, list<mixed>>
     */
    private function upstreamAdvisories(Upstream $upstream, array $names): array
    {
        $answers = [];
        $ask = [];

        foreach ($names as $name) {
            $cached = cache()->get($this->advisoryKey($upstream, $name));

            // Three states, not two. A miss is null and has to be asked; a
            // list — including an empty one — is the upstream saying it covers
            // the name, which is what tells Composer to stop looking; and
            // `false` is the upstream saying it does not cover the name, which
            // is equally worth not asking again for a few minutes. Caching
            // that last case as an empty list would claim a coverage the
            // upstream never offered.
            if ($cached === null) {
                $ask[] = $name;

                continue;
            }

            if (is_array($cached)) {
                $answers[$name] = $cached;
            }
        }

        if ($ask === [] || $this->unreachable($upstream)) {
            return $answers;
        }

        try {
            $response = $this->client($upstream)->advisories($ask);
        } catch (Throwable $exception) {
            $this->markUnreachable($upstream, $exception->getMessage());

            return $answers;
        }

        if (! $response instanceof Response || ! $response->successful()) {
            return $answers;
        }

        $advisories = $response->json('advisories');

        if (! is_array($advisories)) {
            return $answers;
        }

        $minutes = (int) config('registry.mirror.advisory_ttl_minutes');

        // Keyed case-insensitively, because a repository is free to echo back
        // the spelling it stores rather than the one it was sent.
        $byName = [];

        foreach ($advisories as $key => $entry) {
            if (is_string($key) && is_array($entry)) {
                $byName[mb_strtolower($key)] = array_values($entry);
            }
        }

        foreach ($ask as $name) {
            // A name the upstream did not key is one it does not cover, which
            // is not the same as one it found nothing for. Both are cached —
            // both mean "do not ask again for a few minutes" — but only a
            // covered name is returned, so an uncovered one stays absent from
            // the response and Composer keeps looking elsewhere.
            $entry = $byName[$name] ?? false;

            cache()->put($this->advisoryKey($upstream, $name), $entry, now()->addMinutes($minutes));

            if (is_array($entry)) {
                $answers[$name] = $entry;
            }
        }

        return $answers;
    }

    private function advisoryKey(Upstream $upstream, string $name): string
    {
        return "mirror:advisories:{$upstream->getKey()}:{$name}";
    }

    /**
     * The cache row for one package on one upstream, revalidated if stale.
     *
     * The revalidation happens once, however many requests arrive to find the
     * same document stale. Composer resolves a few hundred packages at once
     * and a CI fleet starts a few dozen builds at once, so "cold document, one
     * upstream fetch each" is the ordinary case rather than a race — and the
     * unique index that firstOrCreate leans on further down settles the write
     * without settling anything about the outbound request.
     */
    private function resolve(Upstream $upstream, string $name, bool $dev): ?MirroredPackage
    {
        $cached = $this->cachedPackage($upstream, $name, $dev);

        if ($cached instanceof MirroredPackage && $cached->isFresh()) {
            return $cached;
        }

        $lock = Cache::lock("mirror:refresh:{$upstream->getKey()}:{$name}:".($dev ? 'dev' : 'stable'), self::REFRESH_LOCK_SECONDS);

        try {
            return $lock->block($this->lockWait(), function () use ($upstream, $name, $dev): ?MirroredPackage {
                // Read again inside the lock, because the process that held it
                // was fetching this exact document: the ordinary outcome of
                // having waited is that the answer is already here.
                $current = $this->cachedPackage($upstream, $name, $dev);

                if ($current instanceof MirroredPackage && $current->isFresh()) {
                    return $current;
                }

                return $this->refresh($upstream, $current, $name, $dev);
            });
        } catch (LockTimeoutException) {
            // Whatever is cached, however stale — the same answer this class
            // gives for an upstream that is down, for the same reason. Waiting
            // longer would trade a resolved build for a fresher document.
            return $cached;
        }
    }

    private function cachedPackage(Upstream $upstream, string $name, bool $dev): ?MirroredPackage
    {
        return MirroredPackage::query()
            ->where('upstream_id', $upstream->getKey())
            ->where('name', $name)
            ->where('is_dev', $dev)
            ->first();
    }

    /**
     * How long a request waits for another process's fetch before giving up on
     * it — with what it does instead being the difference between the two
     * places this is used.
     */
    private function lockWait(): int
    {
        return (int) config('registry.mirror.lock_wait_seconds');
    }

    /**
     * Ask the upstream what it has, and record the answer.
     *
     * Every failure path returns what was already cached, however stale. That
     * is the availability promise this feature exists for: an hour-old copy of
     * symfony/console resolves a build, and an error does not. Nothing here
     * can affect a package this registry publishes, which never reaches this
     * code at all.
     */
    private function refresh(Upstream $upstream, ?MirroredPackage $cached, string $name, bool $dev): ?MirroredPackage
    {
        if ($this->unreachable($upstream)) {
            return $cached;
        }

        // Validators are only replayed for a document we still hold. Sending
        // them alongside a negative entry would invite a 304 confirming
        // nothing, since there is no body it could be confirming.
        $revalidating = $cached instanceof MirroredPackage && $cached->found();

        // Bounded on the way in rather than measured on the way out. The
        // ceiling used to be a `strlen` over a string this process had already
        // allocated, which is a check that runs after the damage: one upstream
        // answering with a gigabyte took the worker's memory limit with it and
        // never reached the comparison.
        $sink = BoundedSink::to('php://temp', (int) config('registry.mirror.max_metadata_kilobytes') * 1024);

        try {
            $response = $this->client($upstream)->metadata(
                $dev ? "{$name}~dev" : $name,
                $revalidating ? $cached->upstream_etag : null,
                $revalidating ? $cached->upstream_last_modified : null,
                $sink,
            );
        } catch (OversizedResponse) {
            Log::warning('Refusing an oversized upstream metadata document.', [
                'upstream' => $upstream->url,
                'package' => $name,
                'ceiling_bytes' => $sink->limit(),
            ]);

            // Not a failure of the upstream's, so not a reason to stop asking
            // it about other packages: it answered, and what it answered is
            // not something this registry will hold.
            return $cached;
        } catch (Throwable $exception) {
            $this->markUnreachable($upstream, $exception->getMessage());

            return $cached;
        }

        if ($response->status() === 304 && $cached instanceof MirroredPackage) {
            return $this->confirm($cached);
        }

        // 404 and 410 are the upstream saying it does not have this package,
        // which is an answer worth remembering. Every other unsuccessful
        // status — a 500, a 403 from a credential that has stopped working —
        // is the upstream failing to answer, and must never be recorded as
        // "no such package": that would hide the package for as long as the
        // negative entry lives, and hide the outage entirely.
        if (in_array($response->status(), [404, 410], true)) {
            return $this->remember($upstream, $cached, $name, $dev, null, $response);
        }

        if (! $response->successful()) {
            $this->markUnreachable($upstream, "responded {$response->status()}");

            return $cached;
        }

        // What was written, not what the response says it holds: with a sink
        // in play the two are the same in production and are deliberately not
        // in a test, where the ceiling is the only thing that truncated the
        // body. Reading the sink is reading what this app actually accepted.
        $body = $sink->contents();

        $document = json_decode($body, true);

        // A 200 that is not a Composer document — a proxy's login page, an
        // HTML error, a v1 repository — is the upstream being broken rather
        // than the package being absent, so it is not cached either way.
        if (! is_array($document) || ! is_array($document['packages'] ?? null)) {
            Log::warning('Upstream returned something that is not a Composer metadata document.', [
                'upstream' => $upstream->url,
                'package' => $name,
            ]);

            return $cached;
        }

        // Some repositories answer 200 with an empty `packages` for a name
        // they do not have, rather than 404. Same fact, same negative entry.
        $has = $this->versionsIn($document, $name) !== null;

        return $this->remember($upstream, $cached, $name, $dev, $has ? $body : null, $response);
    }

    /**
     * Record a 304: the upstream confirmed what we hold, so only the freshness
     * clock moves. The digest — and therefore every client's validator — is
     * untouched, which is the point of asking conditionally at all.
     */
    private function confirm(MirroredPackage $cached): MirroredPackage
    {
        $cached->forceFill(['fetched_at' => now()])->save();

        return $cached;
    }

    /**
     * Write what the upstream answered.
     *
     * A body identical to the one already stored moves the freshness clock and
     * nothing else. Upstreams that do not implement conditional requests
     * re-send unchanged documents all day, and letting that move `changed_at`
     * would hand every consumer a new Last-Modified — and a fresh download of
     * bytes they already have — every time the TTL lapsed.
     */
    private function remember(
        Upstream $upstream,
        ?MirroredPackage $cached,
        string $name,
        bool $dev,
        ?string $body,
        Response $response,
    ): MirroredPackage {
        // firstOrCreate rather than a plain insert: a cold package requested by
        // two builds at once has both of them find nothing and then race the
        // unique index, which is a 500 for whichever loses. Laravel catches the
        // violation and re-selects, so the loser updates the winner's row with
        // the same upstream answer.
        $mirrored = $cached ?? MirroredPackage::firstOrCreate([
            'upstream_id' => $upstream->getKey(),
            'name' => $name,
            'is_dev' => $dev,
        ], [
            'fetched_at' => now(),
            'used_at' => now(),
        ]);

        $digest = $body === null ? null : hash('xxh128', $body);

        // `used_at` is deliberately absent: it was set when the row was
        // created, and from then on it means "last served", which is what
        // retention prunes on. A revalidation is not a use.
        $attributes = [
            'fetched_at' => now(),
            'upstream_etag' => $this->header($response, 'ETag'),
            'upstream_last_modified' => $this->header($response, 'Last-Modified'),
        ];

        if ($digest !== $mirrored->digest) {
            $attributes['payload'] = $body;
            $attributes['digest'] = $digest;
            $attributes['changed_at'] = $body === null ? null : now();
        }

        $mirrored->forceFill($attributes)->save();

        return $mirrored;
    }

    private function header(Response $response, string $name): ?string
    {
        $value = $response->header($name);

        return $value === '' ? null : $value;
    }

    /**
     * Whether this upstream is inside its failure backoff.
     *
     * An upstream that has just failed is not asked again for a few minutes.
     * Without this, an outage costs every mirrored lookup a connect timeout —
     * one `composer update` of a few hundred dependencies would spend an hour
     * of wall clock discovering the same thing a few hundred times, and each
     * of those seconds is a PHP worker held open.
     *
     * What the window costs is only freshness on an upstream that has come
     * back, and stale metadata is exactly what a mirror is for.
     */
    private function unreachable(Upstream $upstream): bool
    {
        return cache()->has("mirror:unreachable:{$upstream->getKey()}");
    }

    private function markUnreachable(Upstream $upstream, string $reason): void
    {
        $minutes = (int) config('registry.mirror.failure_backoff_minutes');

        cache()->put("mirror:unreachable:{$upstream->getKey()}", $reason, now()->addMinutes($minutes));

        Log::warning('Upstream is unreachable; serving whatever is already cached.', [
            'upstream' => $upstream->url,
            'reason' => $reason,
            'backoff_minutes' => $minutes,
        ]);
    }

    /**
     * The upstream document with this registry's own dist URLs in it.
     */
    private function rewrite(Repository $repository, MirroredPackage $mirrored): string
    {
        $name = (string) $mirrored->name;

        $document = json_decode((string) $mirrored->payload, true);

        $versions = is_array($document) ? $this->versionsIn($document, $name) : null;

        $rewritten = array_map(
            fn (mixed $version): array => $this->rewriteVersion($repository, $name, is_array($version) ? $version : []),
            $versions ?? [],
        );

        // Reduced to the one package that was asked for, and re-keyed under
        // the name this registry answered on. An upstream document is allowed
        // to carry entries for other packages; serving them would let an
        // upstream decide what names resolve from this repository, which is
        // the whole thing mayMirror() is defending.
        return json_encode([
            ...(is_array($document) ? $document : []),
            'minified' => 'composer/2.0',
            'packages' => [$name => MetadataMinifier::minify($rewritten)],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * One version object with its dist pointed back here.
     *
     * @param  array<mixed>  $version
     * @return array<mixed>
     */
    private function rewriteVersion(Repository $repository, string $name, array $version): array
    {
        // Restated rather than trusted. Composer reads `name` off the version
        // it resolved, so an upstream answering our question about
        // symfony/console with a version calling itself acme/internal would
        // otherwise have chosen a name for this registry to serve.
        $version['name'] = $name;

        $dist = $version['dist'] ?? null;

        if (! is_array($dist)) {
            return $version;
        }

        $reference = $dist['reference'] ?? null;
        $shasum = $dist['shasum'] ?? null;

        // Only pointed here when this registry could actually stand behind the
        // bytes: a zip, named by a usable reference, with the sha1 to check it
        // against. Anything else keeps the upstream's own URL and is fetched
        // by Composer directly — which forfeits the caching for that one
        // version, and is much better than advertising a dist URL here that
        // resolves to nothing, or to bytes nobody could verify.
        if (($dist['type'] ?? null) !== 'zip'
            || ! is_string($reference)
            || preg_match(self::REFERENCE_PATTERN, $reference) !== 1
            || ! is_string($shasum)
            || $shasum === ''
        ) {
            return $version;
        }

        $version['dist']['url'] = $repository->url("/dist/{$name}/{$reference}.zip");

        return $version;
    }

    /**
     * The version list a document holds for one package, expanded, or null
     * when the document does not cover that package at all.
     *
     * The key is matched case-insensitively because a repository is free to
     * echo back the spelling it stores; the difference between "no such
     * package" and "the same package in different case" is the difference
     * between a negative cache entry and a served document.
     *
     * @param  array<mixed>  $document
     * @return list<mixed>|null
     */
    private function versionsIn(array $document, string $name): ?array
    {
        $packages = $document['packages'] ?? [];

        if (! is_array($packages)) {
            return null;
        }

        foreach ($packages as $key => $versions) {
            if (! is_string($key) || mb_strtolower($key) !== $name) {
                continue;
            }

            if (! is_array($versions)) {
                return null;
            }

            /** @var list<mixed> $list */
            $list = array_values($versions);

            // Only a document that says it is minified gets expanded. Both
            // forms are legal, and expanding an already-expanded document
            // would fold every version into the first one's fields.
            return ($document['minified'] ?? null) === 'composer/2.0'
                ? MetadataMinifier::expand($list)
                : $list;
        }

        return null;
    }

    /**
     * The upstream's own dist descriptor for one reference, read out of
     * metadata this registry has already cached.
     *
     * This is what bounds the archive fetcher on the consumer's side: a
     * consumer can only ever make this app download a URL that an upstream
     * published in a document we hold, for a reference that document named.
     *
     * That is a bound on the *request*, not on the address, and the difference
     * is the whole of why EgressPolicy exists. On a repository mirroring
     * packagist.org the party who wrote that URL into the document is whoever
     * published the package — which is to say the general public — so "an
     * address an upstream published" and "an address worth reaching" are two
     * different sets, and only one of them is checked here.
     *
     * @return array{url: string, shasum: string}|null
     */
    private function distFor(Upstream $upstream, string $name, string $reference): ?array
    {
        $documents = MirroredPackage::query()
            ->where('upstream_id', $upstream->getKey())
            ->where('name', $name)
            ->whereNotNull('payload')
            ->get();

        foreach ($documents as $document) {
            $decoded = json_decode((string) $document->payload, true);

            foreach (is_array($decoded) ? ($this->versionsIn($decoded, $name) ?? []) : [] as $version) {
                $dist = is_array($version) ? ($version['dist'] ?? null) : null;

                if (! is_array($dist) || ($dist['type'] ?? null) !== 'zip') {
                    continue;
                }

                $url = $dist['url'] ?? null;
                $shasum = $dist['shasum'] ?? null;

                if (($dist['reference'] ?? null) !== $reference || ! is_string($url) || ! is_string($shasum) || $shasum === '') {
                    continue;
                }

                return ['url' => $url, 'shasum' => $shasum];
            }
        }

        return null;
    }

    /**
     * Download, verify and store one upstream archive.
     *
     * The sha1 check is the reason this registry can serve the bytes at all.
     * Composer verifies a download against the `shasum` in the metadata it
     * read, and that metadata now comes from here — so if this app stored
     * whatever arrived, a compromised or merely broken upstream would have its
     * bytes served under a hash this registry vouched for. Verified here, the
     * consumer's own check confirms a claim that was already true.
     *
     * @param  array{url: string, shasum: string}  $dist
     */
    private function fetchArchive(Upstream $upstream, string $name, string $reference, array $dist): ?MirroredArchive
    {
        // An archive is fetched from whatever host the upstream's metadata
        // named — a CDN or an object store, not the upstream's own API — so
        // nothing here touches the failure backoff, in either direction. A
        // codeload 404 for one deleted repository says nothing about whether
        // packagist.org is answering, and marking the upstream unreachable for
        // it would let any anonymous request for one broken reference switch
        // mirroring off for everybody for minutes at a time.
        $client = $this->client($upstream);

        try {
            // Which is also why the scheme, the host and the addresses it
            // resolves to are somebody else's choice, and are checked before
            // this app spends anything at all on them. The same policy applies
            // to every redirect the download then follows; this is only what
            // makes the first refusal legible in the log.
            $client->assertReachable($dist['url']);
        } catch (EgressRefused $refusal) {
            Log::warning('Refusing to fetch an upstream archive from a destination this registry may not reach.', [
                'upstream' => $upstream->url,
                'package' => $name,
                'reference' => $reference,
                'reason' => $refusal->getMessage(),
            ]);

            return null;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'mirror-archive-');

        if ($temporary === false) {
            return null;
        }

        // The ceiling, enforced as the bytes arrive. It bounds what
        // accumulates on the dist disk, but the disk is not what it protects
        // first: the transfer lands in the system temporary directory, which
        // is usually the root volume and is bounded by nothing but the archive
        // timeout — four minutes of somebody else's bandwidth, times however
        // many requests are in flight. Measuring the file afterwards is a
        // report, not a limit.
        $ceiling = (int) config('registry.mirror.max_archive_megabytes') * 1024 * 1024;

        $sink = null;

        try {
            $sink = BoundedSink::to($temporary, $ceiling);

            $response = $client->download($dist['url'], $sink);

            if (! $response->successful()) {
                Log::warning('Could not download an upstream archive.', [
                    'upstream' => $upstream->url,
                    'package' => $name,
                    'reference' => $reference,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $size = $sink->bytes();

            // Closed before it is read back: what the hash is taken over has
            // to be everything that was written, and the last of it is still
            // in this process until the handle is.
            $sink->close();

            if (! hash_equals($dist['shasum'], (string) sha1_file($temporary))) {
                Log::warning('Upstream archive did not match the sha1 the upstream published for it; refusing to store it.', [
                    'upstream' => $upstream->url,
                    'package' => $name,
                    'reference' => $reference,
                ]);

                return null;
            }

            $path = $this->archives->storeMirrored($upstream, $name, $reference, $temporary);

            // firstOrCreate, not create: two builds starting at once is the
            // ordinary case, not an exotic interleaving, and both of them find
            // no row and then race the unique index. The loser takes the
            // winner's row — the bytes are the same release, verified against
            // the same sha1 — and leaves its own file for mirror:prune, which
            // is what the orphan sweep is for.
            return MirroredArchive::firstOrCreate([
                'upstream_id' => $upstream->getKey(),
                'name' => $name,
                'reference' => $reference,
            ], [
                'path' => $path,
                'shasum' => $dist['shasum'],
                'size' => $size,
                'used_at' => now(),
            ]);
        } catch (OversizedResponse) {
            Log::warning('Refusing an upstream archive over the size ceiling.', [
                'upstream' => $upstream->url,
                'package' => $name,
                'reference' => $reference,
                'ceiling_bytes' => $ceiling,
            ]);

            return null;
        } catch (Throwable $exception) {
            Log::warning('Could not mirror an upstream archive.', [
                'upstream' => $upstream->url,
                'package' => $name,
                'reference' => $reference,
                'reason' => $exception->getMessage(),
            ]);

            return null;
        } finally {
            $sink?->close();

            @unlink($temporary);
        }
    }
}
