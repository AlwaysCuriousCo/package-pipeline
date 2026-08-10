<?php

namespace App\Services;

use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Cuts a subdirectory package's dist out of the whole-repository zipball a
 * provider hands back.
 *
 * Neither GitHub nor GitLab will archive part of a repository in the shape
 * Composer needs — GitHub's zipball is the whole tree, and GitLab's `path`
 * parameter keeps the directory nested exactly where it was. Composer, for its
 * part, strips *one* leading directory when it extracts a dist and installs
 * whatever is under it. So a dist for `packages/widgets` that still says
 * `acme-mono-a1b2c3/packages/widgets/src/...` installs a `packages/` folder
 * into the consumer's vendor directory and finds no composer.json where one
 * has to be. The subtree has to be re-rooted here or the archive is wrong.
 *
 * **Nothing is inflated to do it.** The obvious implementation — extract the
 * subtree, zip it back up — would undo the one property the archive path has
 * always had: an archive is streamed to disk, stored, and served without ever
 * being unpacked, so a 500 MB repository costs 500 MB of disk and almost no
 * memory. Instead the downloaded zip is edited in place through its central
 * directory: entries outside the subtree are deleted, and the ones inside are
 * renamed. libzip copies the compressed data of an entry it was not asked to
 * change byte for byte, so no file in the archive is ever decompressed,
 * recompressed, or held in memory. What it costs is one rewrite of the file
 * (libzip writes a sibling temporary and renames over the original), so the
 * peak is roughly twice the archive on disk and nothing on the heap.
 *
 * @see PackageSynchronizer::import() the one caller
 */
class ArchiveSubtree
{
    /**
     * Reduce the zip at $path to the contents of $subdirectory, re-rooted
     * under a single directory named $root.
     *
     * A single top-level directory rather than bare entries at the zip root,
     * because that is the shape every provider and packagist.org produce and
     * the one Composer's extractor is happiest with. $root is the caller's to
     * choose only so it can be something meaningful in a `unzip -l`; nothing
     * downstream reads it.
     *
     * @throws RuntimeException when the archive does not contain the
     *                          subdirectory, or does not contain a package
     */
    public function reroot(string $path, string $subdirectory, string $root): void
    {
        $archive = new ZipArchive;

        throw_if($archive->open($path) !== true, new RuntimeException(
            "The downloaded archive at {$path} is not a readable zip."
        ));

        try {
            $names = $this->names($archive);
            $prefix = $this->providerPrefix($names).trim($subdirectory, '/').'/';

            $kept = $this->subtree($names, $prefix, $subdirectory, $root);

            // Deleted before anything is renamed. A rename into a name the
            // archive still holds is refused by libzip, and while the two
            // namespaces cannot overlap here — $root is chosen not to collide
            // with the provider's own prefix — depending on that ordering to
            // stay true is not worth the two lines it saves.
            foreach (array_diff_key($names, $kept) as $index => $name) {
                throw_unless($archive->deleteIndex($index), new RuntimeException(
                    "Unable to remove {$name} from the archive."
                ));
            }

            foreach ($kept as $index => $name) {
                throw_unless($archive->renameIndex($index, $name), new RuntimeException(
                    "Unable to move {$names[$index]} to {$name} in the archive."
                ));
            }
        } catch (Throwable $exception) {
            // Discards every pending change rather than writing a half-cut
            // archive over the download the caller still has to clean up.
            $archive->unchangeAll();
            $archive->close();

            throw $exception;
        }

        // Where the work actually happens: until now nothing has been written.
        throw_unless($archive->close(), new RuntimeException(
            "Unable to rewrite the archive at {$path} around {$subdirectory}."
        ));
    }

    /**
     * Every entry in the archive, keyed by index.
     *
     * Read once and up front because the operations below invalidate it:
     * a deleted index answers nothing, and a renamed one answers its new name.
     *
     * @return array<int, string>
     */
    private function names(ZipArchive $archive): array
    {
        $names = [];

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = $archive->getNameIndex($index);

            throw_if($name === false, new RuntimeException(
                "The archive's central directory is unreadable at entry {$index}."
            ));

            $names[$index] = $name;
        }

        return $names;
    }

    /**
     * The single directory a provider wraps its whole archive in — GitHub's
     * `owner-repo-sha/`, GitLab's `project-ref-sha/` — or an empty string when
     * the archive has no such wrapper.
     *
     * Derived from the entries rather than assumed from the provider, because
     * the exact spelling of that directory is neither documented nor stable,
     * and an archive with no wrapper at all (an artifact, a future provider)
     * has to work too.
     *
     * @param  array<int, string>  $names
     */
    private function providerPrefix(array $names): string
    {
        $roots = [];

        foreach ($names as $name) {
            // An entry at the very top of the zip means there is no single
            // wrapping directory, whatever the rest of the archive looks like.
            if (! str_contains($name, '/')) {
                return '';
            }

            $roots[strtok($name, '/')] = true;

            if (count($roots) > 1) {
                return '';
            }
        }

        return $roots === [] ? '' : array_key_first($roots).'/';
    }

    /**
     * The entries that make up the package, keyed by their index in the
     * archive and valued at the name each must be given.
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     *
     * @throws RuntimeException
     */
    private function subtree(array $names, string $prefix, string $subdirectory, string $root): array
    {
        $kept = [];

        foreach ($names as $index => $name) {
            // The directory entry for the subtree itself is dropped along with
            // everything outside it: its whole content is about to be one
            // level up, and $root stands in its place.
            if (! str_starts_with($name, $prefix) || $name === $prefix) {
                continue;
            }

            $relative = substr($name, strlen($prefix));

            // Everything after the prefix is the provider's, and it is about
            // to become a path inside an archive this registry stores and
            // hands to Composer clients to unpack. A git tree cannot hold a
            // `..` component or an absolute path, so an archive that does is
            // not one to re-root and publish under a package's name — it is
            // one to refuse, and let the next sync try again.
            //
            // Backslash counts as a separator here for the same reason it does
            // in Package::normalizeSubdirectory(): the two ends of this feature
            // must agree on what a path component is, and a rule that reads
            // `..\..\evil` as one long filename is a rule an extractor on a
            // different platform will disagree with.
            throw_if(
                preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $relative) === 1 || str_starts_with($relative, '/'),
                new RuntimeException("The repository's archive contains an entry that escapes \"{$subdirectory}\": {$name}."),
            );

            $kept[$index] = $root.'/'.$relative;
        }

        throw_if($kept === [], new RuntimeException(
            "The repository's archive contains no \"{$subdirectory}\" directory."
        ));

        // The manifest was read from the provider's API before the archive was
        // fetched, so its absence here does not mean the package has none — it
        // means this zip is not the tree that manifest came from. Serving it
        // would hand Composer a dist that cannot be installed, and one that
        // claims to be a package it is not; refusing leaves the version
        // unimported and the next sync free to try again.
        throw_unless(in_array($root.'/composer.json', $kept, true), new RuntimeException(
            "The \"{$subdirectory}\" directory in the repository's archive holds no composer.json."
        ));

        return $kept;
    }
}
