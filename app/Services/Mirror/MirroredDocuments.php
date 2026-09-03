<?php

namespace App\Services\Mirror;

use App\Models\MirroredPackage;
use App\Models\Upstream;
use App\Support\HttpTimeouts;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The cached-upstream-document machinery the npm and PyPI mirrors share:
 * freshness with jitter, a fetch lock so a cold document costs one upstream
 * round trip however many builds ask at once, negative caching, the failure
 * backoff, and the write discipline that keeps `changed_at` still when an
 * upstream re-sends bytes it already sent.
 *
 * All of it restates MirrorService's rules — same TTLs, same lock windows,
 * same "every failure serves what is already cached" promise — without
 * touching that class, whose Composer path is the one carrying production
 * traffic today. ponytail: fold MirrorService onto this once the shape has
 * survived contact with real npm and PyPI upstreams.
 *
 * What differs per protocol stays with the caller: the URL to fetch, the
 * Accept header, and what counts as a well-formed document. The fetcher
 * passed in returns the body to store, null for "the upstream does not have
 * this name", and throws for anything that should put the upstream in
 * backoff.
 */
class MirroredDocuments
{
    /**
     * The crash-recovery lifetime of a held fetch lock — sized past the
     * longest a healthy fetch can take, exactly as MirrorService sizes its.
     */
    private const REFRESH_LOCK_SECONDS = HttpTimeouts::API + HttpTimeouts::CONNECT + 30;

    /**
     * The cached row for one name on one upstream, revalidated if stale.
     *
     * $fetch is handed the cached row (to replay its upstream validators) and
     * must return the Response alongside the body it accepted — see refresh()
     * for how each outcome is recorded.
     *
     * @param  Closure(?MirroredPackage): array{response: Response, body: ?string}  $fetch
     */
    public function resolve(Upstream $upstream, string $name, Closure $fetch): ?MirroredPackage
    {
        $cached = $this->cachedDocument($upstream, $name);

        if ($cached instanceof MirroredPackage && $cached->isFresh()) {
            return $cached;
        }

        $lock = Cache::lock("mirror:refresh:{$upstream->getKey()}:{$name}", self::REFRESH_LOCK_SECONDS);

        try {
            return $lock->block(
                (int) config('registry.mirror.lock_wait_seconds'),
                function () use ($upstream, $name, $fetch): ?MirroredPackage {
                    // Re-read inside the lock: whoever held it was fetching
                    // this exact document, so the ordinary outcome of having
                    // waited is that the answer is already here.
                    $current = $this->cachedDocument($upstream, $name);

                    if ($current instanceof MirroredPackage && $current->isFresh()) {
                        return $current;
                    }

                    return $this->refresh($upstream, $current, $name, $fetch);
                },
            );
        } catch (LockTimeoutException) {
            // Whatever is cached, however stale — the same answer an upstream
            // being down gets, for the same reason: waiting longer trades a
            // resolved build for a fresher document.
            return $cached;
        }
    }

    /**
     * Ask the upstream, and record the answer. Every failure path returns
     * what was already cached, however stale — the availability promise
     * mirroring exists for.
     *
     * @param  Closure(?MirroredPackage): array{response: Response, body: ?string}  $fetch
     */
    private function refresh(Upstream $upstream, ?MirroredPackage $cached, string $name, Closure $fetch): ?MirroredPackage
    {
        if ($this->unreachable($upstream)) {
            return $cached;
        }

        try {
            ['response' => $response, 'body' => $body] = $fetch($cached);
        } catch (OversizedResponse) {
            Log::warning('Refusing an oversized upstream document.', [
                'upstream' => $upstream->url,
                'name' => $name,
            ]);

            // The upstream answered; what it answered is just not something
            // this registry will hold. Not a reason to stop asking it about
            // other names.
            return $cached;
        } catch (Throwable $exception) {
            $this->markUnreachable($upstream, $exception->getMessage());

            return $cached;
        }

        if ($response->status() === 304 && $cached instanceof MirroredPackage) {
            // Only the freshness clock moves; the digest — and every client's
            // view of the document — is untouched, which is the point of
            // asking conditionally at all.
            $cached->forceFill(['fetched_at' => now()])->save();

            return $cached;
        }

        // 404 and 410 are the upstream saying it does not have this name —
        // an answer worth remembering. Every other unsuccessful status is the
        // upstream failing to answer, and recording it as "no such package"
        // would hide the package for as long as the negative entry lives.
        if (in_array($response->status(), [404, 410], true)) {
            return $this->remember($upstream, $cached, $name, null, $response);
        }

        if (! $response->successful()) {
            $this->markUnreachable($upstream, "responded {$response->status()}");

            return $cached;
        }

        // A 200 whose body the caller rejected — an HTML error page, a login
        // form, the wrong content type — is the upstream being broken rather
        // than the name being absent, and is cached as neither.
        if ($body === null) {
            Log::warning('Upstream returned something that is not a usable document.', [
                'upstream' => $upstream->url,
                'name' => $name,
            ]);

            return $cached;
        }

        return $this->remember($upstream, $cached, $name, $body, $response);
    }

    /**
     * Write what the upstream answered. A body identical to the one already
     * stored moves the freshness clock and nothing else, so upstreams without
     * conditional requests cannot churn every consumer's validators.
     */
    private function remember(Upstream $upstream, ?MirroredPackage $cached, string $name, ?string $body, Response $response): MirroredPackage
    {
        // firstOrCreate: two builds cold-starting at once both find nothing
        // and race the unique index; the loser takes the winner's row.
        $mirrored = $cached ?? MirroredPackage::firstOrCreate([
            'upstream_id' => $upstream->getKey(),
            'name' => $name,
            'is_dev' => false,
        ], [
            'fetched_at' => now(),
            'used_at' => now(),
        ]);

        $digest = $body === null ? null : hash('xxh128', $body);

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

    /**
     * The document rows an ecosystem mirror keys are always the stable
     * flavour: `is_dev` is Composer's releases/branches split, and neither
     * npm nor PyPI has one.
     */
    private function cachedDocument(Upstream $upstream, string $name): ?MirroredPackage
    {
        return MirroredPackage::query()
            ->where('upstream_id', $upstream->getKey())
            ->where('name', $name)
            ->where('is_dev', false)
            ->first();
    }

    /**
     * The failure backoff, shared cache keys and all with MirrorService: an
     * upstream one surface finds down is down for the other surfaces too.
     */
    public function unreachable(Upstream $upstream): bool
    {
        return cache()->has("mirror:unreachable:{$upstream->getKey()}");
    }

    public function markUnreachable(Upstream $upstream, string $reason): void
    {
        $minutes = (int) config('registry.mirror.failure_backoff_minutes');

        cache()->put("mirror:unreachable:{$upstream->getKey()}", $reason, now()->addMinutes($minutes));

        Log::warning('Upstream is unreachable; serving whatever is already cached.', [
            'upstream' => $upstream->url,
            'reason' => $reason,
            'backoff_minutes' => $minutes,
        ]);
    }

    private function header(Response $response, string $name): ?string
    {
        $value = $response->header($name);

        return $value === '' ? null : $value;
    }
}
