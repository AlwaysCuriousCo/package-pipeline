<?php

namespace App\Services\Mirror;

use App\Models\Upstream;
use App\Support\BoundedSink;
use App\Support\EgressPolicy;
use App\Support\EgressRefused;
use App\Support\HttpTimeouts;
use Closure;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
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
     * @return array{protocol?: string, metadata: ?string, advisories: ?string}
     */
    public function endpoints(?int $budget = null): array
    {
        // The protocol is in the key so that changing it in the panel takes
        // effect on the next request rather than an hour later.
        $key = "mirror:endpoints:{$this->upstream->getKey()}:".md5($this->upstream->url.'|'.$this->upstream->protocol);

        /** @var array{protocol?: string, metadata: ?string, advisories: ?string}|null $cached */
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
        $endpoints = $discovered ?? ['protocol' => 'v2', 'metadata' => '/p2/%package%.json', 'advisories' => null];

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
        $endpoints = $this->endpoints();

        // Endpoints cached before v1 support carry no protocol, and were v2.
        if (($endpoints['protocol'] ?? 'v2') === 'v1') {
            return $this->v1Metadata($package, $sink);
        }

        $url = str_replace('%package%', $package, (string) $endpoints['metadata']);

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
     * The upstream's root document, reduced to the protocol to speak and the
     * URLs this app uses.
     *
     * The operator's choice is taken strictly. Detected, v2 is preferred,
     * because a repository that serves both (Satis does) answers
     * v2 one package at a time instead of in one index.
     *
     * @return array{protocol: string, metadata: ?string, advisories: ?string}|null
     */
    private function discover(?int $budget = null): ?array
    {
        $response = $this->root($budget);

        if (! $response instanceof Response || ! $response->successful()) {
            return null;
        }

        $root = $response->json();

        // An HTML error page that happened to parse, or a document that is
        // neither protocol, is not something to mirror from.
        if (! is_array($root)) {
            return null;
        }

        $supports = self::supports($root);

        $protocol = match ($this->upstream->protocol) {
            'v1', 'v2' => $supports[$this->upstream->protocol] ? $this->upstream->protocol : null,
            default => $supports['v2'] ? 'v2' : ($supports['v1'] ? 'v1' : null),
        };

        if ($protocol === null) {
            return null;
        }

        $advisories = $root['security-advisories']['api-url'] ?? null;

        return [
            'protocol' => $protocol,
            'metadata' => $protocol === 'v2' ? (string) $root['metadata-url'] : null,
            'advisories' => is_string($advisories) && $advisories !== '' ? $advisories : null,
        ];
    }

    private function root(?int $budget = null): ?Response
    {
        $root = $this->upstream->url('/packages.json');

        return rescue(fn (): Response => $this->request($root, $budget)->acceptJson()->get($root), report: false);
    }

    /**
     * Which Composer protocols a root document can be read with.
     *
     * v2 is a `metadata-url` template. v1 is any of the ways a v1 repository
     * can say where its packages are: listed inline (a hand-written or small
     * repository), in `includes` files (Satis), behind a `providers-lazy-url`
     * (Private Packagist, old packagist.org), or in hashed `providers` files.
     *
     * @param  array<mixed>  $root
     * @return array{v2: bool, v1: bool}
     */
    public static function supports(array $root): array
    {
        $metadata = $root['metadata-url'] ?? null;

        return [
            'v2' => is_string($metadata) && str_contains($metadata, '%package%'),
            'v1' => ! empty($root['packages'])
                || ! empty($root['includes'])
                || is_string($root['providers-lazy-url'] ?? null)
                || (is_string($root['providers-url'] ?? null) && (! empty($root['providers']) || ! empty($root['provider-includes']))),
        ];
    }

    /**
     * Ask the upstream what it is, without caching anything — what the
     * panel's Test button shows the operator.
     *
     * @return array{status: ?int, error: ?string, v2: bool, v1: bool, recommended: ?string, details: list<string>}
     */
    public function probe(): array
    {
        $response = $this->root(HttpTimeouts::API);
        $result = ['status' => $response?->status(), 'error' => null, 'v2' => false, 'v1' => false, 'recommended' => null, 'details' => []];

        if (! $response instanceof Response) {
            return [...$result, 'error' => 'The upstream could not be reached.'];
        }

        if (in_array($response->status(), [401, 403], true)) {
            return [...$result, 'error' => "The upstream refused the credentials ({$response->status()}). Check the username and token."];
        }

        $root = $response->successful() ? $response->json() : null;

        if (! is_array($root)) {
            return [...$result, 'error' => "packages.json answered {$response->status()} with something that is not a Composer repository."];
        }

        $supports = self::supports($root);
        $details = [];

        if ($supports['v2']) {
            $details[] = "v2 metadata-url: {$root['metadata-url']}";
        }

        if (! empty($root['packages']) && is_array($root['packages'])) {
            $details[] = 'v1: '.count($root['packages']).' package(s) listed in packages.json';
        }

        if (! empty($root['includes']) && is_array($root['includes'])) {
            $details[] = 'v1: '.count($root['includes']).' include file(s)';
        }

        if (is_string($root['providers-lazy-url'] ?? null)) {
            $details[] = "v1 providers-lazy-url: {$root['providers-lazy-url']}";
        } elseif ($supports['v1'] && is_string($root['providers-url'] ?? null)) {
            $details[] = "v1 providers-url: {$root['providers-url']}";
        }

        return [
            ...$result,
            ...$supports,
            'recommended' => $supports['v2'] ? 'v2' : ($supports['v1'] ? 'v1' : null),
            'details' => $details,
            'error' => $supports['v2'] || $supports['v1'] ? null : 'packages.json names no packages, includes, providers or metadata-url.',
        ];
    }

    /**
     * One package's v1 metadata, answered in the shape a v2 `/p2` request
     * gets, so everything downstream — caching, kept versions, rewriting —
     * stays one code path.
     *
     * v1 serves releases and branches together; v2 asks for them apart
     * (`name` and `name~dev`), so the split is made here.
     */
    private function v1Metadata(string $package, BoundedSink $sink): Response
    {
        $dev = str_ends_with($package, '~dev');
        $name = $dev ? substr($package, 0, -4) : $package;

        $versions = $this->v1Versions($name);

        if ($versions === null) {
            return new Response(new Psr7Response(404));
        }

        $versions = array_values(array_filter(
            $versions,
            fn (mixed $version): bool => is_array($version) && self::isBranch($version) === $dev,
        ));

        fwrite($sink->stream(), json_encode(['packages' => [$name => $versions]], JSON_THROW_ON_ERROR));

        if ($sink->exceeded()) {
            throw new OversizedResponse('The upstream answered with more bytes than this registry accepts.');
        }

        return new Response(new Psr7Response(200));
    }

    /**
     * @param  array<mixed>  $version
     */
    private static function isBranch(array $version): bool
    {
        $name = (string) ($version['version'] ?? '');

        return str_starts_with($name, 'dev-') || str_ends_with($name, '-dev');
    }

    /**
     * Every version a v1 repository holds for one package, or null when it
     * holds none.
     *
     * @return list<mixed>|null
     */
    private function v1Versions(string $name): ?array
    {
        $index = $this->v1Index();

        if (isset($index['packages'][$name])) {
            return $index['packages'][$name];
        }

        if ($index['lazy'] !== null) {
            $document = $this->v1Json(str_replace('%package%', $name, $index['lazy']), missing: true);

            return $document === null ? null : self::v1Package($document, $name);
        }

        $hash = $index['providers'][$name] ?? null;

        if ($hash === null || $index['providers-url'] === null) {
            return null;
        }

        $document = $this->v1Json(str_replace(['%package%', '%hash%'], [$name, $hash], $index['providers-url']), 'sha256', $hash, missing: true);

        return $document === null ? null : self::v1Package($document, $name);
    }

    /**
     * Everything a v1 root says about where its packages are, with the
     * inline and included packages read in full.
     *
     * Cached for the metadata TTL, per upstream, because every package on the
     * upstream is answered out of the same index and fetching it once per
     * package per hour would be fetching it once per package per hour.
     *
     * ponytail: the whole index sits in the cache store — fine for a vendor's
     * private repository, not for a Satis build of thousands of packages.
     * Store it per package if one of those ever needs mirroring.
     *
     * @return array{packages: array<string, list<mixed>>, providers: array<string, string>, providers-url: ?string, lazy: ?string}
     */
    private function v1Index(): array
    {
        $key = "mirror:v1index:{$this->upstream->getKey()}:".md5($this->upstream->url);

        /** @var array{packages: array<string, list<mixed>>, providers: array<string, string>, providers-url: ?string, lazy: ?string}|null $cached */
        $cached = cache()->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $root = $this->v1Json('/packages.json') ?? [];

        $index = ['packages' => [], 'providers' => [], 'providers-url' => null, 'lazy' => null];

        $documents = [$root];

        foreach (is_array($root['includes'] ?? null) ? $root['includes'] : [] as $path => $hashes) {
            $sha1 = is_array($hashes) && is_string($hashes['sha1'] ?? null) ? $hashes['sha1'] : null;
            $documents[] = $this->v1Json((string) $path, $sha1 === null ? null : 'sha1', $sha1) ?? [];
        }

        foreach ($documents as $document) {
            foreach (is_array($document['packages'] ?? null) ? $document['packages'] : [] as $package => $versions) {
                if (is_string($package) && is_array($versions)) {
                    $index['packages'][mb_strtolower($package)] = array_values($versions);
                }
            }
        }

        $providerDocuments = [$root];

        foreach (is_array($root['provider-includes'] ?? null) ? $root['provider-includes'] : [] as $path => $hashes) {
            $sha256 = is_array($hashes) ? ($hashes['sha256'] ?? null) : null;

            if (is_string($sha256) && preg_match('/^[a-f0-9]{64}$/', $sha256) === 1) {
                $providerDocuments[] = $this->v1Json(str_replace('%hash%', $sha256, (string) $path), 'sha256', $sha256) ?? [];
            }
        }

        foreach ($providerDocuments as $document) {
            foreach (is_array($document['providers'] ?? null) ? $document['providers'] : [] as $package => $hashes) {
                $sha256 = is_array($hashes) ? ($hashes['sha256'] ?? null) : null;

                // Validated because it is spliced into a URL.
                if (is_string($package) && is_string($sha256) && preg_match('/^[a-f0-9]{64}$/', $sha256) === 1) {
                    $index['providers'][mb_strtolower($package)] = $sha256;
                }
            }
        }

        $index['providers-url'] = is_string($root['providers-url'] ?? null) ? $root['providers-url'] : null;
        $index['lazy'] = is_string($root['providers-lazy-url'] ?? null) ? $root['providers-lazy-url'] : null;

        cache()->put($key, $index, now()->addMinutes((int) config('registry.mirror.metadata_ttl_minutes')));

        return $index;
    }

    /**
     * One v1 document's versions for a package, matched case-insensitively.
     *
     * @param  array<mixed>  $document
     * @return list<mixed>|null
     */
    private static function v1Package(array $document, string $name): ?array
    {
        foreach (is_array($document['packages'] ?? null) ? $document['packages'] : [] as $package => $versions) {
            if (is_string($package) && mb_strtolower($package) === $name && is_array($versions)) {
                return array_values($versions);
            }
        }

        return null;
    }

    /**
     * Fetch, bound, verify and decode one v1 JSON document.
     *
     * A failure throws, so the caller treats the upstream as down rather than
     * the package as missing — the same rule the v2 path follows. The one
     * exception is a per-package document, where a 404 *is* "no such package".
     *
     * @return array<mixed>|null
     */
    private function v1Json(string $path, ?string $algorithm = null, ?string $hash = null, bool $missing = false): ?array
    {
        $url = $this->absolute($path);
        $sink = BoundedSink::to('php://temp', (int) config('registry.mirror.max_metadata_kilobytes') * 1024);

        $response = $this->bounded($sink, fn (): Response => $this->request($url)->withOptions($this->into($sink))->get($url));

        if ($missing && in_array($response->status(), [404, 410], true)) {
            return null;
        }

        if (! $response->successful()) {
            throw new RuntimeException("{$url} responded {$response->status()}.");
        }

        $body = $sink->contents();

        // What the index published is what Composer itself would verify.
        if ($algorithm !== null && ! hash_equals(mb_strtolower((string) $hash), hash($algorithm, $body))) {
            throw new RuntimeException("{$url} does not match its published {$algorithm}.");
        }

        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new RuntimeException("{$url} is not a Composer document.");
        }

        return $decoded;
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

        // Most Composer repositories ignore the username — this app's own
        // instructions are `composer config http-basic.<host> token <token>` —
        // so `token` is the default. Licence servers that check it get the one
        // the operator configured.
        return $request->withBasicAuth($this->upstream->basicUsername(), (string) $this->upstream->token);
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
