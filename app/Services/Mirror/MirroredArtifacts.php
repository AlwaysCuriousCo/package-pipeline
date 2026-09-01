<?php

namespace App\Services\Mirror;

use App\Models\MirroredArchive;
use App\Models\Upstream;
use App\Services\ArchiveStore;
use App\Support\BoundedSink;
use App\Support\EgressRefused;
use App\Support\HttpTimeouts;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The fetch-verify-store flow for one mirrored artifact — an npm tarball, a
 * Python wheel — shared by the ecosystem mirrors as MirroredDocuments shares
 * their metadata flow, and restating MirrorService's rules for the same
 * reason: the installation-wide fetch lock, the size ceiling enforced as the
 * bytes arrive, the egress policy on the URL somebody else wrote, and no row
 * written unless the bytes verified.
 *
 * What differs per protocol stays with the caller: where the artifact lives
 * and how its bytes are checked, both read out of a document this registry
 * already cached — which is what bounds the URLs a consumer can make this
 * app fetch to the set the upstream published.
 */
class MirroredArtifacts
{
    private const ARCHIVE_LOCK_SECONDS = HttpTimeouts::ARCHIVE + HttpTimeouts::CONNECT + 30;

    public function __construct(
        private readonly ArchiveStore $archives,
    ) {}

    /**
     * The stored artifact for one reference, fetching and verifying it the
     * first time, or null when this registry will not serve one.
     *
     * $locate answers, per upstream, where the artifact lives and how to
     * check it — or null when that upstream's cached document does not name
     * the reference:
     *
     * @param  Collection<int, Upstream>  $upstreams
     * @param  Closure(Upstream): ?array{client: EcosystemUpstreamClient, url: string, verify: Closure(string): bool}  $locate
     */
    public function resolve(Collection $upstreams, string $name, string $reference, Closure $locate): ?MirroredArchive
    {
        $stored = $this->stored($upstreams, $name, $reference);

        if ($stored instanceof MirroredArchive) {
            return $stored;
        }

        // One fetch of one artifact at a time across the installation: a CI
        // fleet starting fifty builds at once is the ordinary case, and the
        // winner's bytes serve all of them.
        $lock = Cache::lock("mirror:archive:{$name}:{$reference}", self::ARCHIVE_LOCK_SECONDS);

        try {
            return $lock->block(
                (int) config('registry.mirror.lock_wait_seconds'),
                fn (): ?MirroredArchive => $this->stored($upstreams, $name, $reference)
                    ?? $this->fetchFromUpstreams($upstreams, $name, $reference, $locate),
            );
        } catch (LockTimeoutException) {
            // Somebody is still downloading it. Doing the work twice is
            // waste; answering 404 for a file a build needs is a broken
            // install, and there is no third option that is not one of those.
            return $this->fetchFromUpstreams($upstreams, $name, $reference, $locate);
        }
    }

    /**
     * The artifact already held, or null — deleting a row whose file has
     * gone, because a mirror can always repair itself from the upstream.
     *
     * @param  Collection<int, Upstream>  $upstreams
     */
    private function stored(Collection $upstreams, string $name, string $reference): ?MirroredArchive
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

        $existing->delete();

        return null;
    }

    /**
     * @param  Collection<int, Upstream>  $upstreams
     * @param  Closure(Upstream): ?array{client: EcosystemUpstreamClient, url: string, verify: Closure(string): bool}  $locate
     */
    private function fetchFromUpstreams(Collection $upstreams, string $name, string $reference, Closure $locate): ?MirroredArchive
    {
        foreach ($upstreams as $upstream) {
            $located = $locate($upstream);

            if (! is_array($located)) {
                continue;
            }

            $artifact = $this->fetch($upstream, $located['client'], $name, $reference, $located['url'], $located['verify']);

            if ($artifact instanceof MirroredArchive) {
                return $artifact;
            }
        }

        return null;
    }

    /**
     * Download, verify and store one artifact. Every refusal is a null — an
     * unreachable destination, a failed download, a size over the ceiling, a
     * body that did not verify — because they are all the same thing from the
     * consumer's side: no bytes this registry will stand behind.
     *
     * @param  Closure(string): bool  $verify  given the local path, whether the bytes match what the upstream published
     */
    private function fetch(
        Upstream $upstream,
        EcosystemUpstreamClient $client,
        string $name,
        string $reference,
        string $url,
        Closure $verify,
    ): ?MirroredArchive {
        try {
            // Checked before anything is spent, so the refusal is legible in
            // the log; the client's middleware enforces the same policy on
            // every hop the download then follows.
            $client->assertReachable($url);
        } catch (EgressRefused $refusal) {
            Log::warning('Refusing to fetch an upstream artifact from a destination this registry may not reach.', [
                'upstream' => $upstream->url,
                'name' => $name,
                'reference' => $reference,
                'reason' => $refusal->getMessage(),
            ]);

            return null;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'mirror-artifact-');

        if ($temporary === false) {
            return null;
        }

        $ceiling = (int) config('registry.mirror.max_archive_megabytes') * 1024 * 1024;

        $sink = null;

        try {
            $sink = BoundedSink::to($temporary, $ceiling);

            $response = $client->download($url, $sink);

            if (! $response->successful()) {
                Log::warning('Could not download an upstream artifact.', [
                    'upstream' => $upstream->url,
                    'name' => $name,
                    'reference' => $reference,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $size = $sink->bytes();

            // Closed before it is verified: the hash has to cover everything
            // that was written, and the last of it is in this process until
            // the handle is.
            $sink->close();

            if (! $verify($temporary)) {
                Log::warning('Upstream artifact did not match the digest the upstream published for it; refusing to store it.', [
                    'upstream' => $upstream->url,
                    'name' => $name,
                    'reference' => $reference,
                ]);

                return null;
            }

            $path = $this->archives->storeMirrored($upstream, $name, $reference, $temporary);

            // firstOrCreate for the two-builds-at-once case; the loser takes
            // the winner's row and its own file goes to mirror:prune's
            // orphan sweep.
            return MirroredArchive::firstOrCreate([
                'upstream_id' => $upstream->getKey(),
                'name' => $name,
                'reference' => $reference,
            ], [
                'path' => $path,
                // The column holds a sha1 wherever the row came from; for an
                // artifact verified by a stronger digest this is bookkeeping
                // computed from the verified bytes, not the check itself.
                'shasum' => (string) sha1_file($temporary),
                'size' => $size,
                'used_at' => now(),
            ]);
        } catch (OversizedResponse) {
            Log::warning('Refusing an upstream artifact over the size ceiling.', [
                'upstream' => $upstream->url,
                'name' => $name,
                'reference' => $reference,
                'ceiling_bytes' => $ceiling,
            ]);

            return null;
        } catch (Throwable $exception) {
            Log::warning('Could not mirror an upstream artifact.', [
                'upstream' => $upstream->url,
                'name' => $name,
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
