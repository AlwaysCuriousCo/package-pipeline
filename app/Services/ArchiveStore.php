<?php

namespace App\Services;

use App\Models\PackageVersion;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        $path = "packages/{$version->package->name}/".Str::uuid7().'.zip';

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

        $version->forceFill([
            'archive_path' => $path,
            'shasum' => sha1_file($zip),
        ])->save();
    }
}
