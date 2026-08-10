<?php

namespace App\Services\Mirror;

use App\Models\Upstream;
use App\Support\HttpTimeouts;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Speaks Composer v2 to one upstream repository.
 *
 * Everything this app knows about how to *reach* an upstream lives here;
 * MirrorService decides what to ask for and what to keep. The split matters
 * because an upstream is not necessarily packagist.org — it may be a corporate
 * proxy or another installation of this app — and the only honest way to find
 * out where its documents live is to read its own root document rather than to
 * assume packagist.org's URL shapes.
 */
final class UpstreamClient
{
    public function __construct(private readonly Upstream $upstream) {}

    /**
     * Where this upstream serves package metadata and advisories, discovered
     * from its `packages.json` and remembered.
     *
     * Cached because it is configuration, not content: a repository's URL
     * templates change when its operator redeploys it, not when a package is
     * released. A failed discovery is cached too, for much less time — an
     * upstream that is down would otherwise cost a second round trip (and a
     * second connect timeout) in front of every metadata request that was
     * already going to fail.
     *
     * @return array{metadata: string, advisories: ?string}
     */
    public function endpoints(): array
    {
        $key = "mirror:endpoints:{$this->upstream->getKey()}:".md5($this->upstream->url);

        /** @var array{metadata: string, advisories: ?string}|null $cached */
        $cached = cache()->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $discovered = $this->discover();

        $minutes = (int) config($discovered === null
            ? 'registry.mirror.missing_ttl_minutes'
            : 'registry.mirror.metadata_ttl_minutes');

        // The fallback is packagist.org's layout, which is also this app's own
        // and is what a Composer v2 repository serves unless it says otherwise.
        // Caching it means a momentarily unreachable upstream still gets its
        // metadata requests attempted rather than skipped — the metadata fetch
        // is the one that matters, and it is about to fail or succeed on its
        // own merits.
        $endpoints = $discovered ?? ['metadata' => '/p2/%package%.json', 'advisories' => null];

        cache()->put($key, $endpoints, now()->addMinutes($minutes));

        return $endpoints;
    }

    /**
     * Fetch one metadata document, revalidating what we already hold.
     *
     * The validators are the upstream's own strings, handed straight back to
     * it: an unchanged package answers 304 with no body, which is what makes a
     * TTL affordable at all — expiry costs a round trip, not a download.
     */
    public function metadata(string $package, ?string $etag, ?string $lastModified): Response
    {
        $url = str_replace('%package%', $package, $this->endpoints()['metadata']);

        $absolute = $this->absolute($url);

        return $this->request($absolute)
            ->when($etag !== null, fn (PendingRequest $request) => $request->withHeader('If-None-Match', (string) $etag))
            ->when($lastModified !== null, fn (PendingRequest $request) => $request->withHeader('If-Modified-Since', (string) $lastModified))
            ->get($absolute);
    }

    /**
     * Ask the upstream about vulnerabilities in the named packages.
     *
     * POST with `packages[]`, which is the shape Composer's own client sends
     * and therefore the one every implementation of this endpoint accepts.
     *
     * @param  list<string>  $packages
     */
    public function advisories(array $packages): ?Response
    {
        $url = $this->endpoints()['advisories'];

        if ($url === null) {
            return null;
        }

        $absolute = $this->absolute($url);

        return $this->request($absolute)->asForm()->post($absolute, ['packages' => $packages]);
    }

    /**
     * Stream an upstream archive to a local file.
     *
     * The URL is never taken from the client's request — it comes from a
     * metadata document this registry has already fetched and cached, so the
     * set of addresses a consumer can make this app reach is exactly the set
     * the upstream published.
     */
    public function download(string $url, string $destination): Response
    {
        return $this->request($url)
            // How long this takes is a property of the archive, not of the
            // upstream's health, so the API budget would cut a large release
            // off mid-stream.
            ->timeout(HttpTimeouts::ARCHIVE)
            ->sink($destination)
            ->get($url);
    }

    /**
     * The upstream's root document, reduced to the two URLs this app uses.
     *
     * @return array{metadata: string, advisories: ?string}|null
     */
    private function discover(): ?array
    {
        $root = $this->upstream->url('/packages.json');

        $response = rescue(fn (): Response => $this->request($root)->acceptJson()->get($root));

        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }

        $metadata = $response->json('metadata-url');

        // A repository with no metadata-url is not a Composer v2 repository at
        // all — a v1 `packages.json` with everything inlined, or an HTML error
        // page that happened to parse. Neither is something to mirror from.
        if (! is_string($metadata) || ! str_contains($metadata, '%package%')) {
            return null;
        }

        $advisories = $response->json('security-advisories.api-url');

        return [
            'metadata' => $metadata,
            'advisories' => is_string($advisories) && $advisories !== '' ? $advisories : null,
        ];
    }

    /**
     * A URL from the upstream's root document made absolute.
     *
     * Composer repositories state these paths either way — packagist.org's
     * metadata-url is site-relative, its advisory api-url is absolute, and
     * this app emits one of each — so both are accepted rather than one being
     * declared correct.
     */
    private function absolute(string $url): string
    {
        return Str::startsWith($url, ['http://', 'https://'])
            ? $url
            : $this->upstream->url('/'.ltrim($url, '/'));
    }

    /**
     * A request to the given URL, credentialed only if it is going to the
     * upstream itself.
     *
     * The host check is the point. An upstream's metadata names the host its
     * archives live on — codeload.github.com for a packagist-shaped upstream,
     * an object store for a self-hosted one — and that host is chosen by the
     * upstream, not by us. Attaching the credential unconditionally would mean
     * the operator's token for their private registry is spent, as an
     * Authorization header, against whatever third party that registry happens
     * to name. Guzzle strips the header across a redirect for exactly this
     * reason; there is no reason to put it on the first request either.
     *
     * It is also what makes pre-signed dist URLs work at all: an object store
     * refuses a request that carries both a signature and an Authorization
     * header, so the credentialed version would fail against every upstream
     * that signs its downloads.
     */
    private function request(string $url): PendingRequest
    {
        $request = Http::timeout(HttpTimeouts::API)->connectTimeout(HttpTimeouts::CONNECT);

        if (blank($this->upstream->token) || ! $this->isUpstreamHost($url)) {
            return $request;
        }

        // The username is ignored by every Composer repository that reads a
        // token this way — including this app, whose own instructions are
        // `composer config http-basic.<host> token <your-token>` — so one
        // credential field is all an upstream needs.
        return $request->withBasicAuth('token', (string) $this->upstream->token);
    }

    /**
     * Whether a URL addresses the upstream itself, scheme and host and port.
     *
     * All three, because none of them alone is the same origin: a token sent
     * to the http:// spelling of an https:// upstream is a token sent in
     * clear, and a different port is a different service.
     */
    private function isUpstreamHost(string $url): bool
    {
        $target = parse_url($url);
        $upstream = parse_url($this->upstream->url);

        if (! is_array($target) || ! is_array($upstream)) {
            return false;
        }

        return ($target['scheme'] ?? null) === ($upstream['scheme'] ?? null)
            && mb_strtolower((string) ($target['host'] ?? '')) === mb_strtolower((string) ($upstream['host'] ?? ''))
            && ($target['port'] ?? null) === ($upstream['port'] ?? null);
    }
}
