<?php

namespace App\Services\Mirror;

use App\Enums\Ecosystem;
use App\Models\MirroredArchive;
use App\Models\MirroredPackage;
use App\Models\Package;
use App\Models\Repository;
use App\Models\ReservedVendor;
use App\Models\Upstream;
use App\Support\BoundedSink;
use App\Support\EgressPolicy;
use App\Support\NpmName;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;

/**
 * Serves npm packages this registry does not publish, from a repository's
 * npm upstreams, out of the same cache tables and under the same rules as
 * the Composer mirror — above all the unconditional one: **a local package
 * always wins**. A name published anywhere in this installation, in any
 * ecosystem, is never answered from npmjs.org, which is the whole of the
 * dependency-confusion defence.
 *
 * @see MirrorService for the Composer sibling and the reasoning both follow
 * @see docs/mirroring.md
 */
class NpmMirror
{
    /**
     * Bumped whenever the rewriting below changes shape; folded into the
     * rendered-payload cache key so a deploy supersedes every entry.
     */
    private const PAYLOAD_REVISION = 1;

    /**
     * What may stand between `/npm/{name}/-/` and the end of the URL: a
     * tarball basename, which becomes a URL segment, a storage path segment
     * and a MirroredArchive reference. `+` beyond the Composer reference
     * charset, because semver build metadata lands in filenames.
     */
    public const FILENAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._+-]{0,254}$/D';

    public function __construct(
        private readonly MirroredDocuments $documents,
        private readonly MirroredArtifacts $artifacts,
        private readonly EgressPolicy $egress,
    ) {}

    /**
     * Whether this repository may answer for this name out of an npm
     * upstream. The same three refusals as the Composer mirror, in the same
     * order: mirroring off, not an npm name, and — the one that matters — a
     * name or vendor this installation already owns, in any ecosystem,
     * compared as bluntly as MirrorService compares its own.
     */
    public function mayMirror(Repository $repository, string $name): bool
    {
        if (! $repository->mirrors(Ecosystem::Npm) || ! NpmName::valid($name)) {
            return false;
        }

        $published = Package::query()
            ->whereIn(DB::raw('lower(name)'), [mb_strtolower($name)])
            ->exists();

        return ! $published
            && ! ReservedVendor::query()->where('vendor', ReservedVendor::normalize($name))->exists();
    }

    /**
     * The cached upstream packument for one package, fetched or revalidated
     * as needed, or null when no npm upstream has it. First upstream that
     * answers wins, in the operator's order.
     */
    public function packument(Repository $repository, string $name): ?MirroredPackage
    {
        if (! $this->mayMirror($repository, $name)) {
            return null;
        }

        foreach ($repository->activeUpstreams(Ecosystem::Npm) as $upstream) {
            $mirrored = $this->documents->resolve(
                $upstream,
                $name,
                fn (?MirroredPackage $cached): array => $this->fetch($upstream, $name, $cached),
            );

            if ($mirrored instanceof MirroredPackage && $mirrored->found()) {
                $mirrored->markUsed();

                return $mirrored;
            }
        }

        return null;
    }

    /**
     * One packument fetch, in the shape MirroredDocuments records.
     *
     * The abbreviated form is requested — it carries exactly what `npm
     * install` resolves from, at a fraction of the full document's weight —
     * and anything without a `versions` map is reported as unusable rather
     * than cached as an answer.
     *
     * @return array{response: Response, body: ?string}
     */
    private function fetch(Upstream $upstream, string $name, ?MirroredPackage $cached): array
    {
        $sink = BoundedSink::to('php://temp', (int) config('registry.mirror.max_metadata_kilobytes') * 1024);

        $revalidating = $cached instanceof MirroredPackage && $cached->found();

        $response = $this->client($upstream)->document(
            // The scoped slash encoded, which every npm registry accepts and
            // some require; an unscoped name passes through unchanged.
            $upstream->url('/'.str_replace('/', '%2f', $name)),
            'application/vnd.npm.install-v1+json',
            $revalidating ? $cached->upstream_etag : null,
            $revalidating ? $cached->upstream_last_modified : null,
            $sink,
        );

        $body = $sink->contents();

        $document = json_decode($body, true);

        $usable = is_array($document) && is_array($document['versions'] ?? null);

        return ['response' => $response, 'body' => $usable ? $body : null];
    }

    /**
     * The bytes to serve for one mirrored packument: the upstream document
     * with every verifiable tarball pointed back here, cached under the same
     * supersede-not-invalidate discipline as every rendered payload.
     */
    public function render(Repository $repository, MirroredPackage $mirrored): string
    {
        $key = 'npm:mirror:'.$mirrored->getKey().':'.hash('xxh128', implode('|', [
            self::PAYLOAD_REVISION,
            $repository->url('/npm/'),
            (string) $mirrored->digest,
        ]));

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
     * The upstream packument with this registry's own tarball URLs in it.
     *
     * Reduced to the keys npm resolves from, and re-keyed under the name
     * this registry answered on — an upstream must not choose what names
     * resolve from here, which is the same rule the Composer rewrite states.
     */
    private function rewrite(Repository $repository, MirroredPackage $mirrored): string
    {
        $name = (string) $mirrored->name;

        $document = json_decode((string) $mirrored->payload, true);
        $document = is_array($document) ? $document : [];

        $versions = [];

        foreach (is_array($document['versions'] ?? null) ? $document['versions'] : [] as $key => $manifest) {
            if (! is_array($manifest)) {
                continue;
            }

            // Restated rather than trusted, exactly as the Composer rewrite
            // restates it: npm reads `name` off the version it resolved.
            $manifest['name'] = $name;

            $versions[$key] = $this->rewriteVersion($repository, $name, $manifest);
        }

        $distTags = is_array($document['dist-tags'] ?? null) ? $document['dist-tags'] : [];

        return json_encode([
            'name' => $name,
            'dist-tags' => (object) $distTags,
            'versions' => (object) $versions,
            ...(is_string($document['modified'] ?? null) ? ['modified' => $document['modified']] : []),
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * One version with its tarball pointed back here — only when this
     * registry could stand behind the bytes: a parseable filename and a
     * digest to verify a download against. Anything else keeps the
     * upstream's own URL, which forfeits the caching for that version and is
     * much better than advertising bytes nobody could check.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    private function rewriteVersion(Repository $repository, string $name, array $manifest): array
    {
        $dist = $manifest['dist'] ?? null;

        if (! is_array($dist)) {
            return $manifest;
        }

        $filename = $this->filenameFrom($dist['tarball'] ?? null);

        if ($filename === null || $this->verifier($dist) === null) {
            return $manifest;
        }

        $manifest['dist']['tarball'] = $repository->url("/npm/{$name}/-/{$filename}");

        return $manifest;
    }

    /**
     * The stored tarball for one filename of one mirrored package, fetched
     * and verified the first time, or null when this registry will not serve
     * one.
     */
    public function tarball(Repository $repository, string $name, string $filename): ?MirroredArchive
    {
        if (! $this->mayMirror($repository, $name) || preg_match(self::FILENAME_PATTERN, $filename) !== 1) {
            return null;
        }

        return $this->artifacts->resolve(
            $repository->activeUpstreams(Ecosystem::Npm),
            $name,
            $filename,
            function (Upstream $upstream) use ($name, $filename): ?array {
                $located = $this->distFor($upstream, $name, $filename);

                if ($located === null) {
                    return null;
                }

                return [...$located, 'client' => $this->client($upstream)];
            },
        );
    }

    /**
     * The upstream's own URL and digest for one tarball, read out of the
     * packument this registry already cached — which is what bounds the
     * addresses a consumer can make this app fetch to the set the upstream
     * published.
     *
     * @return array{url: string, verify: Closure(string): bool}|null
     */
    private function distFor(Upstream $upstream, string $name, string $filename): ?array
    {
        $document = MirroredPackage::query()
            ->where('upstream_id', $upstream->getKey())
            ->where('name', $name)
            ->whereNotNull('payload')
            ->first();

        if (! $document instanceof MirroredPackage) {
            return null;
        }

        $decoded = json_decode((string) $document->payload, true);

        foreach (is_array($decoded) && is_array($decoded['versions'] ?? null) ? $decoded['versions'] : [] as $manifest) {
            $dist = is_array($manifest) ? ($manifest['dist'] ?? null) : null;

            if (! is_array($dist) || $this->filenameFrom($dist['tarball'] ?? null) !== $filename) {
                continue;
            }

            $url = $dist['tarball'];
            $verify = $this->verifier($dist);

            if (! is_string($url) || $verify === null) {
                continue;
            }

            return ['url' => $url, 'verify' => $verify];
        }

        return null;
    }

    /**
     * The basename a tarball URL serves under, or null for one this registry
     * will not address — because the name is what becomes the URL segment,
     * the archive reference and half a storage path.
     */
    private function filenameFrom(mixed $tarball): ?string
    {
        if (! is_string($tarball)) {
            return null;
        }

        $path = parse_url($tarball, PHP_URL_PATH);

        $basename = rawurldecode(basename(is_string($path) ? $path : ''));

        return preg_match(self::FILENAME_PATTERN, $basename) === 1 ? $basename : null;
    }

    /**
     * How a downloaded tarball is checked against what the upstream
     * published: the strongest algorithm its `integrity` names, or the sha1
     * `shasum`, or nothing — and with nothing there is nothing to mirror.
     *
     * @param  array<mixed>  $dist
     * @return Closure(string): bool|null
     */
    private function verifier(array $dist): ?Closure
    {
        $integrity = $dist['integrity'] ?? null;

        if (is_string($integrity)) {
            // An integrity string may carry several space-separated entries;
            // srihash's rule is that the strongest wins, and these are every
            // algorithm both PHP and the SRI spec speak.
            foreach (['sha512', 'sha384', 'sha256'] as $algorithm) {
                foreach (explode(' ', $integrity) as $entry) {
                    if (str_starts_with($entry, "{$algorithm}-")) {
                        $expected = substr($entry, strlen($algorithm) + 1);

                        return fn (string $path): bool => hash_equals(
                            $expected,
                            base64_encode((string) hash_file($algorithm, $path, true)),
                        );
                    }
                }
            }
        }

        $shasum = $dist['shasum'] ?? null;

        if (is_string($shasum) && $shasum !== '') {
            return fn (string $path): bool => hash_equals(mb_strtolower($shasum), (string) sha1_file($path));
        }

        return null;
    }

    private function client(Upstream $upstream): EcosystemUpstreamClient
    {
        return new EcosystemUpstreamClient($upstream, $this->egress);
    }
}
