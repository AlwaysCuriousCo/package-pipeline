<?php

namespace Tests\Support;

use App\Services\ArchiveSubtree;
use ZipArchive;

/**
 * A stand-in for the zipball a provider hands back for a commit.
 *
 * Sync tests used to fake that download with a short string, which worked only
 * as long as nothing opened it. Every archive is re-rooted now — the provider's
 * `owner-repo-sha/` wrapper becomes the package's own name — so the download
 * has to be a zip a `ZipArchive` can read, with a composer.json where the
 * synchronizer expects to find one.
 *
 * @see ArchiveSubtree
 */
class Zipball
{
    /**
     * Zip bytes shaped the way a provider's archive of a whole repository is:
     * one wrapping directory named for the repository and the commit, with the
     * package at its root.
     *
     * @param  array<string, string>  $files  entry name inside the wrapper => contents
     */
    public static function bytes(
        array $files = ['composer.json' => '{"name":"acme/widgets"}'],
        string $root = 'acme-widgets-a1b2c3d',
    ): string {
        $path = tempnam(sys_get_temp_dir(), 'zipball-test-');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addEmptyDir($root);

        foreach ($files as $name => $contents) {
            $zip->addFromString("{$root}/{$name}", $contents);
        }

        $zip->close();

        $bytes = (string) file_get_contents($path);

        unlink($path);

        return $bytes;
    }
}
