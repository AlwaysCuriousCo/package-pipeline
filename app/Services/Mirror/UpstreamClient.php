<?php

namespace App\Services\Mirror;

use App\Models\Upstream;
use App\Support\BoundedSink;
use App\Support\EgressPolicy;
use App\Support\EgressRefused;
use App\Support\HttpTimeouts;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Throwable;

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
    public function __construct(
        private readonly Upstream $upstream,
        private readonly EgressPolicy $egress,
    ) {}

    /**
     * Refuse a destination before anything is spent reaching it.
     *
     * The middleware below applies the same policy to every request that
     * leaves this class, so this is not the enforcement — it is what lets a
     * caller log *why* an archive was refused instead of logging that a
     * download failed, and what keeps a temporary file from being created for
     * a fetch that was never going to happen.
     *
     * @throws EgressRefused
     */
    public function assertReachable(string $url): void
    {
        if (! $this->isUpstreamHost($url)) {
            $this->egress->addressesFor($url);
        }
    }

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
     * @param  int|null  $budget  seconds this discovery may take, when the
     *                            caller is working inside one
     * @return array{metadata: string, advisories: ?string}
     */
    public function endpoints(?int $budget = null): array
    {
        $key = "mirror:endpoints:{$this->upstream->getKey()}:".md5($this->upstream->url);

        /** @var array{metadata: string, advisories: ?string}|null $cached */
        $cached = cache()->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $discovered = $this->discover($budget);

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
     *
     * Streamed into the sink rather than read off the response, because
     * `->body()` is a string this process has already allocated by the time
     * anyone can measure it: one upstream answering a `/p2` request with a
     * gigabyte would exhaust the worker's memory limit before the metadata
     * ceiling was so much as consulted.
     *
     * @throws OversizedResponse
     */
    public function metadata(string $package, ?string $etag, ?string $lastModified, BoundedSink $sink): Response
    {
        $url = str_replace('%package%', $package, $this->endpoints()['metadata']);

        $absolute = $this->absolute($url);

        return $this->bounded($sink, fn (): Response => $this->request($absolute)
            ->withOptions($this->into($sink))
            ->when($etag !== null, fn (PendingRequest $request) => $request->withHeader('If-None-Match', (string) $etag))
            ->when($lastModified !== null, fn (PendingRequest $request) => $request->withHeader('If-Modified-Since', (string) $lastModified))
            ->get($absolute));
    }

    /**
     * Ask the upstream about vulnerabilities in the named packages.
     *
     * POST with `packages[]`, which is the shape Composer's own client sends
     * and therefore the one every implementation of this endpoint accepts.
     *
     * On a budget, including the discovery in front of it, because this whole
     * call happens inside the ten seconds Composer allows the request it is
     * answering. See HttpTimeouts::ADVISORY.
     *
     * @param  list<string>  $packages
     */
    public function advisories(array $packages): ?Response
    {
        $url = $this->endpoints(HttpTimeouts::ADVISORY)['advisories'];

        if ($url === null) {
            return null;
        }

        $absolute = $this->absolute($url);

        return $this->request($absolute, HttpTimeouts::ADVISORY)->asForm()->post($absolute, ['packages' => $packages]);
    }

    /**
     * Stream an upstream archive to a local file.
     *
     * The URL is never taken from the client's request — it comes from a
     * metadata document this registry has already fetched and cached. That
     * bounds the set of addresses a consumer can reach to the set the upstream
     * published, which on a public upstream is a set the public writes; the
     * egress policy is what bounds it to addresses worth reaching.
     *
     * @throws OversizedResponse
     */
    public function download(string $url, BoundedSink $sink): Response
    {
        return $this->bounded($sink, fn (): Response => $this->request($url)
            // How long this takes is a property of the archive, not of the
            // upstream's health, so the API budget would cut a large release
            // off mid-stream. It is also why the size ceiling cannot be left
            // until afterwards: four minutes is a great many bytes.
            ->timeout(HttpTimeouts::ARCHIVE)
            ->withOptions($this->into($sink))
            ->get($url));
    }

    /**
     * The options that make a response land in the sink, and stop early when
     * the upstream says up front that it is too big.
     *
     * The `Content-Length` is a claim from the same party as the body, so it
     * can only ever refuse a transfer and never permit one — which is exactly
     * what it is used for. Believing an honest upstream costs nothing and
     * saves the whole download; disbelieving a dishonest one costs nothing
     * either, because the sink is what actually bounds the bytes.
     *
     * @return array<string, mixed>
     */
    private function into(BoundedSink $sink): array
    {
        return [
            'sink' => $sink->stream(),
            'on_headers' => function (ResponseInterface $response) use ($sink): void {
                if ((int) $response->getHeaderLine('Content-Length') > $sink->limit()) {
                    $sink->refuse();

                    throw new OversizedResponse('The upstream announced more bytes than this registry accepts.');
                }
            },
        ];
    }

    /**
     * Send a request that streams into a sink, and report a refused transfer
     * as one thing rather than as three.
     *
     * A sink that has stopped accepting bytes reaches the caller in whichever
     * way the handler happens to express a broken write — an exception out of
     * libcurl, a short body, a response that looks fine — so the sink is what
     * is asked, not the outcome. What the caller gets either way is a refusal
     * it can tell apart from an upstream being down, because those two want
     * opposite responses: one is worth logging and moving on from, the other
     * puts the upstream in backoff.
     *
     * @param  Closure(): Response  $send
     *
     * @throws OversizedResponse
     */
    private function bounded(BoundedSink $sink, Closure $send): Response
    {
        try {
            $response = $send();
        } catch (Throwable $exception) {
            if ($sink->exceeded()) {
                throw new OversizedResponse('The upstream answered with more bytes than this registry accepts.', 0, $exception);
            }

            throw $exception;
        }

        if ($sink->exceeded()) {
            throw new OversizedResponse('The upstream answered with more bytes than this registry accepts.');
        }

        return $response;
    }

    /**
     * The upstream's root document, reduced to the two URLs this app uses.
     *
     * @return array{metadata: string, advisories: ?string}|null
     */
    private function discover(?int $budget = null): ?array
    {
        $root = $this->upstream->url('/packages.json');

        $response = rescue(fn (): Response => $this->request($root, $budget)->acceptJson()->get($root));

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
    private function request(string $url, ?int $budget = null): PendingRequest
    {
        // A budget bounds the connection as well as the read, because a
        // caller that has ten seconds has them for the whole request and an
        // upstream that never completes its handshake would spend them there.
        $request = Http::timeout($budget ?? HttpTimeouts::API)
            ->connectTimeout(min($budget ?? HttpTimeouts::CONNECT, HttpTimeouts::CONNECT))
            // Stated rather than left to Guzzle's defaults, which are the same
            // five hops but say nothing about which schemes may be redirected
            // to. Every one of those hops is a destination of the upstream's
            // choosing, which is why the egress policy is a middleware and not
            // a check on the URL this method was handed: it is re-applied
            // below the redirect handler, once per hop.
            ->withOptions(['allow_redirects' => ['max' => 5, 'protocols' => ['http', 'https']]])
            ->withMiddleware($this->egress->middleware($this->isUpstreamHost(...)));

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
     *
     * This one predicate answers both questions this class asks about a
     * destination, and they are the same question: whether the operator chose
     * it. If they did, their credential may travel to it, and the egress
     * policy has nothing to add — an operator who configured an upstream at
     * `http://nexus.internal:8081` has already said this app may reach that
     * address, and it is the ordinary shape of a self-hosted upstream. If they
     * did not, the destination was chosen by the upstream — or by whoever
     * published a package on it — and neither is true of it.
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
