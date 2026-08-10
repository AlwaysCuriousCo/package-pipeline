<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The validators a provider handed out for a repository's tag and branch
 * listings, so the next sync can ask whether a page has changed instead of
 * downloading it again.
 *
 * Every sync lists every tag and every branch, because that is the only way to
 * learn what moved — and for a webhook-covered package that is once per push,
 * plus hourly from the schedule, to be told the same few hundred refs it was
 * told last time. A conditional request turns the common answer into a 304
 * with no body, which on GitHub is also free of the primary rate limit
 * entirely. The refs themselves are kept beside the ETag because a 304 carries
 * no body: without them there would be nothing to answer the sync with.
 *
 * Nothing here is authoritative. A miss, an expiry, or a provider that sends
 * no ETag costs one ordinary listing, which is what every sync did before.
 */
final class RefListingCache
{
    /**
     * Long enough that a quiet repository keeps paying nothing, short enough
     * that an entry for a package nobody syncs any more does not sit in the
     * cache forever. Each full listing rewrites it, so an actively synced
     * repository's entry never ages out.
     */
    private const TTL_DAYS = 7;

    /**
     * @param  string  $scope  what makes one repository's listings distinct
     *                         from another's: provider, API root, and path
     */
    public function __construct(private readonly string $scope) {}

    /**
     * What was cached for a page, or null when nothing usable was.
     *
     * The cache is a shared store this class does not own, so what comes back
     * is re-typed rather than trusted: a partial or foreign entry reads as a
     * miss and costs one ordinary listing.
     *
     * @return array{etag: string, refs: array<string, string>, next: bool}|null
     */
    public function get(string $endpoint, int $page): ?array
    {
        $cached = Cache::get($this->key($endpoint, $page));

        if (! is_array($cached) || ! is_string($cached['etag'] ?? null) || ! is_array($cached['refs'] ?? null)) {
            return null;
        }

        $refs = [];

        foreach ($cached['refs'] as $name => $sha) {
            $refs[(string) $name] = (string) $sha;
        }

        return ['etag' => $cached['etag'], 'refs' => $refs, 'next' => (bool) ($cached['next'] ?? false)];
    }

    /**
     * Remember a page as the provider just served it.
     *
     * `next` travels with the refs because a 304 says nothing about
     * pagination either, and stopping a page early would silently drop every
     * ref after it.
     *
     * @param  array<string, string>  $refs
     */
    public function put(string $endpoint, int $page, string $etag, array $refs, bool $next): void
    {
        Cache::put(
            $this->key($endpoint, $page),
            ['etag' => $etag, 'refs' => $refs, 'next' => $next],
            now()->addDays(self::TTL_DAYS),
        );
    }

    /**
     * Hashed because the scope carries an API URL and a repository path,
     * neither of which is safe in a cache key as typed.
     */
    private function key(string $endpoint, int $page): string
    {
        return 'refs.'.hash('xxh128', "{$this->scope}|{$endpoint}|{$page}");
    }
}
