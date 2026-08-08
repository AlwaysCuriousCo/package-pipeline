<?php

namespace App\Services;

use App\Models\PackageVersion;
use Illuminate\Contracts\Filesystem\Filesystem;
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
     * The disk archives live on. The dist endpoint and archives:clean read
     * from here too, so all three always agree on where archives are.
     */
    public function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.dists'));
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
