<?php

namespace App\Services;

use App\Models\PackageVersion;
use App\Models\Upstream;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\WhitespacePathNormalizer;
use RuntimeException;
use Throwable;

/**
 * Keeps every package version's zip on the dist disk, so metadata and dist
 * downloads are served from storage the app controls instead of being
 * proxied from GitHub per request.
 */
class ArchiveStore
{
    /**
     * Where archives of packages *this registry publishes* live.
     *
     * archives:clean and archives:audit both list exactly this prefix, and
     * both reconcile the disk against `package_versions`. Widening either
     * listing to the whole disk would make every mirrored archive an orphan
     * with no row to claim it — so the separation below is not tidiness, it is
     * what keeps those two commands from deleting the mirror cache.
     */
    public const PUBLISHED_PREFIX = 'packages';

    /**
     * Where archives fetched from an upstream live. Reconciled by
     * `mirror:prune` against `mirrored_archives`, and by nothing else.
     */
    public const MIRROR_PREFIX = 'mirror';

    /**
     * How long a pre-signed archive URL is good for.
     *
     * A storage service checks the signature when the transfer *starts* and
     * does not cut off one that outlives the URL, so this window only has to
     * cover a client following the redirect it was handed — plus a retry, and
     * whatever clock skew stands between this app and the service. Minutes are
     * already generous for that; hours would only lengthen how long a URL that
     * leaked into a proxy log or a CI transcript stays spendable.
     */
    private const URL_LIFETIME_MINUTES = 5;

    /**
     * The disk archives live on. The dist endpoint and archives:clean read
     * from here too, so all three always agree on where archives are.
     */
    public function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.dists'));
    }

    /**
     * A URL the client can fetch this archive from directly, or null when the
     * disk has none to give and the app has to serve the bytes itself.
     *
     * A local disk is deliberately excluded even though `serve` lets it sign
     * URLs: that signed route is answered by this same application, so
     * redirecting to it would cost a round trip and hand the transfer straight
     * back to PHP. Only a disk whose URLs somebody else answers takes work off
     * the app, which is the whole reason to redirect.
     */
    public function temporaryUrl(string $path): ?string
    {
        $disk = $this->disk();

        if (! $disk instanceof FilesystemAdapter || $disk instanceof LocalFilesystemAdapter) {
            return null;
        }

        if (! $disk->providesTemporaryUrls()) {
            return null;
        }

        return $disk->temporaryUrl($path, now()->addMinutes(self::URL_LIFETIME_MINUTES));
    }

    /**
     * Store a local zip as the version's archive, recording where it went and
     * the sha1 Composer verifies downloads against.
     *
     * The path is keyed by a fresh uuid rather than the git reference: a
     * re-stored version never overwrites a file a concurrent download may be
     * streaming — it writes a new one and leaves the old to archives:clean.
     */
    public function store(PackageVersion $version, string $zip): void
    {
        $path = $this->under(self::PUBLISHED_PREFIX, "{$version->package->name}/".Str::uuid7().'.zip');

        $this->write($path, $zip);

        $version->forceFill([
            'archive_path' => $path,
            'shasum' => sha1_file($zip),
        ])->save();
    }

    /**
     * Store a verified upstream archive, returning where it went.
     *
     * No row is written here — the caller does that, because it is the caller
     * that checked the bytes against the shasum the upstream published, and a
     * `mirrored_archives` row exists precisely to record that the check passed.
     *
     * Keyed by a fresh uuid for the same reason a published archive is: two
     * builds cold-starting at once both fetch the same release, and a
     * deterministic path would have one of them truncating the file the other
     * is streaming to a client. The reference is immutable, so the bytes would
     * be identical — but a half-written file is not the bytes.
     *
     * The loser's file is claimed by no row and is swept by `mirror:prune`,
     * which is what its orphan pass is for.
     */
    public function storeMirrored(Upstream $upstream, string $name, string $reference, string $zip): string
    {
        $path = $this->under(
            self::MIRROR_PREFIX,
            "{$upstream->getKey()}/{$name}/{$reference}/".Str::uuid7().'.zip',
        );

        $this->write($path, $zip);

        return $path;
    }

    /**
     * The path a key resolves to under one of the two prefixes, refusing any
     * key that resolves somewhere else.
     *
     * Both keys above are built by interpolating a package name — a string
     * this registry read out of somebody's composer.json, or out of an
     * upstream's index. Those callers validate it, and this asks again anyway,
     * because the separation of the two prefixes is what stops archives:clean
     * and mirror:prune deleting each other's files, and a guard that only
     * holds while every caller remembers is not a guard.
     *
     * Flysystem is no help here on its own: it refuses a path that climbs out
     * of the disk root, but `packages/../mirror/x` does not — it resolves
     * quietly to `mirror/x`, which is a real place on this disk owned by a
     * different sweep. So the question has to be asked about the *resolved*
     * path, using the normalizer the disk itself will use, rather than about
     * the string that was handed over.
     *
     * The resolved path is what gets returned and recorded, too. The
     * alternative is a row that says `packages/a/../b.zip` while the object
     * lives at `packages/b.zip`, and both sweeps compare these as strings.
     */
    private function under(string $prefix, string $key): string
    {
        $path = "{$prefix}/{$key}";

        try {
            $resolved = (new WhitespacePathNormalizer)->normalizePath($path);
        } catch (Throwable $exception) {
            throw new RuntimeException("Refusing to store an archive at [{$path}].", previous: $exception);
        }

        throw_unless(str_starts_with($resolved, "{$prefix}/"), new RuntimeException(
            "Refusing to store an archive at [{$path}]: it resolves to [{$resolved}], outside the [{$prefix}] prefix."
        ));

        return $resolved;
    }

    /**
     * Copy a local file onto the dist disk, leaving nothing behind if it fails.
     */
    private function write(string $path, string $zip): void
    {
        $stream = fopen($zip, 'r');

        throw_if($stream === false, new RuntimeException("Unable to read the downloaded archive at {$zip}."));

        try {
            throw_if(
                $this->disk()->writeStream($path, $stream) === false,
                new RuntimeException("Unable to write {$path} to the dist disk."),
            );
        } catch (Throwable $exception) {
            // A write that fails part way through can leave a truncated object
            // behind, which a later download would serve as the real archive.
            $this->disk()->delete($path);

            throw $exception;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
