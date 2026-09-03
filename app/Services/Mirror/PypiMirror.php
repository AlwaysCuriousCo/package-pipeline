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
use App\Support\PypiName;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;

/**
 * Serves Python projects this registry does not publish, from a repository's
 * PyPI upstreams — the third mirror, following the other two's rules, the
 * unconditional local-package-wins defence above all.
 *
 * What is cached is the upstream's PEP 691 JSON project page; what is served
 * is this registry's own PEP 503 HTML built from it, with every file that
 * carries a sha256 pointed back here. An upstream that only speaks HTML is
 * not mirrored — parsing somebody's HTML to re-serve it verifiable is a
 * project of its own, and pypi.org, devpi and every serious proxy speak the
 * JSON form.
 *
 * @see MirrorService
 * @see docs/mirroring.md
 */
class PypiMirror
{
    /**
     * A distribution filename this registry will address: what the simple
     * index anchors, an archive reference and half a storage path. `+` for
     * local version segments, `!` for epochs.
     */
    public const FILENAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._+!-]{0,254}$/D';

    public function __construct(
        private readonly MirroredDocuments $documents,
        private readonly MirroredArtifacts $artifacts,
        private readonly EgressPolicy $egress,
    ) {}

    /**
     * The same three refusals as the other mirrors: mirroring off, not a
     * normalized project name, a name or vendor this installation owns in
     * any ecosystem.
     */
    public function mayMirror(Repository $repository, string $name): bool
    {
        if (! $repository->mirrors(Ecosystem::Pypi) || $name !== PypiName::normalize($name) || $name === '') {
            return false;
        }

        $published = Package::query()
            ->whereIn(DB::raw('lower(name)'), [$name])
            ->exists();

        return ! $published
            && ! ReservedVendor::query()->where('vendor', ReservedVendor::normalize($name))->exists();
    }

    /**
     * The cached upstream project page for one name, or null when no PyPI
     * upstream has it. First upstream that answers wins, in the operator's
     * order.
     */
    public function project(Repository $repository, string $name): ?MirroredPackage
    {
        if (! $this->mayMirror($repository, $name)) {
            return null;
        }

        foreach ($repository->activeUpstreams(Ecosystem::Pypi) as $upstream) {
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
     * One PEP 691 fetch, in the shape MirroredDocuments records. A 200 that
     * is not the JSON form — an index that ignored the Accept header and
     * answered HTML — is reported as unusable rather than cached.
     *
     * @return array{response: Response, body: ?string}
     */
    private function fetch(Upstream $upstream, string $name, ?MirroredPackage $cached): array
    {
        $sink = BoundedSink::to('php://temp', (int) config('registry.mirror.max_metadata_kilobytes') * 1024);

        $revalidating = $cached instanceof MirroredPackage && $cached->found();

        $response = $this->client($upstream)->document(
            $upstream->url("/{$name}/"),
            'application/vnd.pypi.simple.v1+json',
            $revalidating ? $cached->upstream_etag : null,
            $revalidating ? $cached->upstream_last_modified : null,
            $sink,
        );

        $body = $sink->contents();

        $document = json_decode($body, true);

        $usable = is_array($document) && is_array($document['files'] ?? null);

        return ['response' => $response, 'body' => $usable ? $body : null];
    }

    /**
     * The anchors this registry's own project page serves for a mirrored
     * project — already escaped, ready for the controller's page shell.
     *
     * A file with a sha256 is pointed back here, hash fragment and all; one
     * without keeps the upstream's own URL, verifiable by nobody and
     * therefore not something this registry will re-serve. `requires-python`
     * and `yanked` ride along because pip's resolver reads both.
     *
     * @return list<string>
     */
    public function anchors(Repository $repository, MirroredPackage $mirrored): array
    {
        $name = (string) $mirrored->name;

        $document = json_decode((string) $mirrored->payload, true);

        $anchors = [];

        foreach (is_array($document) && is_array($document['files'] ?? null) ? $document['files'] : [] as $file) {
            if (! is_array($file)) {
                continue;
            }

            $filename = $file['filename'] ?? null;
            $url = $file['url'] ?? null;

            if (! is_string($filename) || preg_match(self::FILENAME_PATTERN, $filename) !== 1 || ! is_string($url)) {
                continue;
            }

            $sha256 = is_array($file['hashes'] ?? null) && is_string($file['hashes']['sha256'] ?? null)
                ? mb_strtolower($file['hashes']['sha256'])
                : null;

            $href = $sha256 !== null
                ? $repository->url("/pypi/files/{$name}/-/{$filename}").'#sha256='.$sha256
                : $url;

            $requires = is_string($file['requires-python'] ?? null)
                ? ' data-requires-python="'.e($file['requires-python']).'"'
                : '';

            // PEP 592: truthy `yanked` (a bare true, or the reason as a
            // string) makes pip avoid the file unless it is pinned exactly.
            $yankedValue = $file['yanked'] ?? false;
            $yanked = match (true) {
                is_string($yankedValue) && $yankedValue !== '' => ' data-yanked="'.e($yankedValue).'"',
                $yankedValue === true => ' data-yanked=""',
                default => '',
            };

            $anchors[] = '<a href="'.e($href).'"'.$requires.$yanked.'>'.e($filename).'</a>';
        }

        return $anchors;
    }

    /**
     * The stored distribution file for one filename of one mirrored project,
     * fetched and sha256-verified the first time, or null when this registry
     * will not serve one.
     */
    public function file(Repository $repository, string $name, string $filename): ?MirroredArchive
    {
        if (! $this->mayMirror($repository, $name) || preg_match(self::FILENAME_PATTERN, $filename) !== 1) {
            return null;
        }

        return $this->artifacts->resolve(
            $repository->activeUpstreams(Ecosystem::Pypi),
            $name,
            $filename,
            function (Upstream $upstream) use ($name, $filename): ?array {
                $located = $this->fileFor($upstream, $name, $filename);

                if ($located === null) {
                    return null;
                }

                return [...$located, 'client' => $this->client($upstream)];
            },
        );
    }

    /**
     * The upstream's own URL and sha256 for one file, read out of the cached
     * project page — the same bound on fetchable addresses every mirror
     * keeps. A file the upstream published without a sha256 was never
     * pointed here at all, so it is not located either.
     *
     * @return array{url: string, verify: Closure(string): bool}|null
     */
    private function fileFor(Upstream $upstream, string $name, string $filename): ?array
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

        foreach (is_array($decoded) && is_array($decoded['files'] ?? null) ? $decoded['files'] : [] as $file) {
            if (! is_array($file) || ($file['filename'] ?? null) !== $filename) {
                continue;
            }

            $url = $file['url'] ?? null;
            $sha256 = is_array($file['hashes'] ?? null) && is_string($file['hashes']['sha256'] ?? null)
                ? mb_strtolower($file['hashes']['sha256'])
                : null;

            if (! is_string($url) || $sha256 === null) {
                continue;
            }

            return [
                'url' => $url,
                'verify' => fn (string $path): bool => hash_equals($sha256, (string) hash_file('sha256', $path)),
            ];
        }

        return null;
    }

    private function client(Upstream $upstream): EcosystemUpstreamClient
    {
        return new EcosystemUpstreamClient($upstream, $this->egress);
    }
}
