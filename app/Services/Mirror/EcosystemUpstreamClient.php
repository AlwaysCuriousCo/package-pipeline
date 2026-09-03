<?php

namespace App\Services\Mirror;

use App\Enums\Ecosystem;
use App\Models\Upstream;
use App\Support\BoundedSink;
use App\Support\EgressPolicy;
use App\Support\EgressRefused;
use App\Support\HttpTimeouts;
use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Reaches an npm or PyPI upstream — the transport half of mirroring those
 * ecosystems, as UpstreamClient is for Composer.
 *
 * A separate class rather than a generalization of UpstreamClient, on
 * purpose: that client's discovery (a Composer root document), its auth
 * spelling (the basic `token` user) and its conditional-request shape are all
 * Composer's, and the transport rules the two share — the egress policy on
 * every hop, the credential confined to the upstream's own origin, the size
 * ceiling enforced as the bytes arrive — are restated here rather than
 * threaded through a shared base whose seams nobody asked for.
 * ponytail: fold the two into one transport when a third protocol arrives.
 */
final class EcosystemUpstreamClient
{
    public function __construct(
        private readonly Upstream $upstream,
        private readonly EgressPolicy $egress,
    ) {}

    /**
     * Refuse a destination before anything is spent reaching it — the same
     * legibility helper UpstreamClient offers, for the same reason: the
     * middleware below is the enforcement, this is what makes a refusal
     * loggable as one.
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
     * Fetch one document, revalidating what we already hold.
     *
     * Streamed into the sink for the reason every upstream read is: measuring
     * `->body()` measures a string this worker has already allocated.
     *
     * @throws OversizedResponse
     */
    public function document(string $url, string $accept, ?string $etag, ?string $lastModified, BoundedSink $sink): Response
    {
        return $this->bounded($sink, fn (): Response => $this->request($url)
            ->withHeader('Accept', $accept)
            ->withOptions($this->into($sink))
            ->when($etag !== null, fn (PendingRequest $request) => $request->withHeader('If-None-Match', (string) $etag))
            ->when($lastModified !== null, fn (PendingRequest $request) => $request->withHeader('If-Modified-Since', (string) $lastModified))
            ->get($url));
    }

    /**
     * Stream an upstream artifact to a local file. The URL comes from a
     * document this registry already cached, never from the client's request
     * — the same bound the Composer archive fetch relies on.
     *
     * @throws OversizedResponse
     */
    public function download(string $url, BoundedSink $sink): Response
    {
        return $this->bounded($sink, fn (): Response => $this->request($url)
            // Sized by the artifact, not by the upstream's health — the API
            // budget would cut a large wheel off mid-stream.
            ->timeout(HttpTimeouts::ARCHIVE)
            ->withOptions($this->into($sink))
            ->get($url));
    }

    /**
     * @return array<string, mixed>
     */
    private function into(BoundedSink $sink): array
    {
        return [
            'sink' => $sink->stream(),
            // The Content-Length is the upstream's own claim, so it may only
            // ever refuse a transfer; the sink is what actually bounds it.
            'on_headers' => function (ResponseInterface $response) use ($sink): void {
                if ((int) $response->getHeaderLine('Content-Length') > $sink->limit()) {
                    $sink->refuse();

                    throw new OversizedResponse('The upstream announced more bytes than this registry accepts.');
                }
            },
        ];
    }

    /**
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
     * A request to the given URL, credentialed only when it addresses the
     * upstream itself — an npm registry's tarball host, like a Composer
     * upstream's dist host, is the upstream's choice and must never see the
     * operator's token.
     *
     * The token travels the way each ecosystem's own clients send theirs:
     * npm as a bearer token, PyPI as the basic password behind `__token__`.
     */
    private function request(string $url): PendingRequest
    {
        $request = Http::timeout(HttpTimeouts::API)
            ->connectTimeout(HttpTimeouts::CONNECT)
            ->withOptions(['allow_redirects' => ['max' => 5, 'protocols' => ['http', 'https']]])
            ->withMiddleware($this->egress->middleware($this->isUpstreamHost(...)));

        if (blank($this->upstream->token) || ! $this->isUpstreamHost($url)) {
            return $request;
        }

        return $this->upstream->ecosystem === Ecosystem::Pypi
            ? $request->withBasicAuth('__token__', (string) $this->upstream->token)
            : $request->withToken((string) $this->upstream->token);
    }

    /**
     * Whether a URL addresses the upstream itself — scheme, host and port,
     * for the reasons UpstreamClient spells out on its own copy.
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
