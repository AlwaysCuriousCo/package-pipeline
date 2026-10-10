<?php

namespace App\Services;

use App\Enums\WordPressKind;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Builds the zip WordPress installs from the dist Composer is served.
 *
 * WordPress unpacks an update into wp-content/plugins (or themes) and uses the
 * archive's single top-level directory as the install directory, so the zip
 * has to be rooted at exactly the slug. The Composer dist is already re-rooted
 * — provider wrapper stripped, monorepo subdirectory cut out — under the
 * package's own name, so this is that zip copied and renamed once more. The
 * Composer archive itself is never touched: its sha1 is pinned in lockfiles.
 *
 * The header is read here too, from the bytes that will be served, so the
 * version WordPress is told about is the version it will find after install.
 *
 * @see PackageSynchronizer::import() the one caller
 */
class WordPressArchive
{
    /**
     * How much of a header file WordPress itself reads (get_file_data()).
     */
    private const HEADER_BYTES = 8192;

    /**
     * A CHANGELOG.md beyond this is cut rather than stored whole: it is shown
     * in a modal, and the row holding it is read on every info request.
     */
    private const CHANGELOG_BYTES = 65536;

    /**
     * The header names read, keyed by what they are stored as. The first two
     * differ between plugins and themes and are filled in per kind.
     */
    private const HEADERS = [
        'version' => 'Version',
        'requires' => 'Requires at least',
        'tested' => 'Tested up to',
        'requires_php' => 'Requires PHP',
        'author' => 'Author',
        'author_uri' => 'Author URI',
        'description' => 'Description',
    ];

    public function __construct(private readonly ArchiveSubtree $subtrees = new ArchiveSubtree) {}

    /**
     * A temporary copy of $composerZip rooted at $slug, and what its header
     * says. The caller owns the file and deletes it.
     *
     * @return array{0: string, 1: array<string, string|null>}
     */
    public function build(string $composerZip, string $slug, WordPressKind $kind): array
    {
        $copy = tempnam(sys_get_temp_dir(), 'wordpress-archive-');

        throw_if($copy === false || ! copy($composerZip, $copy), new RuntimeException(
            'Unable to copy the archive for its WordPress zip.'
        ));

        try {
            // The Composer dist has one top-level directory, so with no
            // subdirectory this is purely the rename.
            $this->subtrees->reroot($copy, '', $slug);

            return [$copy, $this->header($copy, $slug, $kind)];
        } catch (Throwable $exception) {
            File::delete($copy);

            throw $exception;
        }
    }

    /**
     * The header fields, a null where the header does not say, plus the file
     * they were read from and the changelog when the package ships one.
     *
     * A zip with no header file at all is still stored: the Composer side of
     * the same version is fine, and a null version is what makes the sync say
     * so rather than drop the release.
     *
     * @return array<string, string|null>
     */
    private function header(string $path, string $slug, WordPressKind $kind): array
    {
        $zip = new ZipArchive;

        throw_if($zip->open($path) !== true, new RuntimeException("The WordPress archive at {$path} is not a readable zip."));

        try {
            [$file, $headers] = $kind === WordPressKind::Theme
                ? $this->themeHeader($zip, $slug)
                : $this->pluginHeader($zip, $slug);

            $changelog = $zip->getFromName("{$slug}/CHANGELOG.md", self::CHANGELOG_BYTES);

            return [
                'slug' => $slug,
                'file' => $file,
                ...$headers,
                'changelog' => is_string($changelog) && $changelog !== '' ? $changelog : null,
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * WordPress takes any top-level PHP file carrying a `Plugin Name` as the
     * plugin; `{slug}.php` is asked first because it nearly always is.
     *
     * @return array{0: ?string, 1: array<string, string|null>}
     */
    private function pluginHeader(ZipArchive $zip, string $slug): array
    {
        $candidates = [];

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (preg_match('#^'.preg_quote($slug, '#').'/[^/]+\.php$#i', $name) === 1) {
                $candidates[] = $name;
            }
        }

        usort($candidates, fn (string $a, string $b): int => [$a !== "{$slug}/{$slug}.php", $a] <=> [$b !== "{$slug}/{$slug}.php", $b]);

        foreach ($candidates as $candidate) {
            $headers = $this->read((string) $zip->getFromName($candidate, self::HEADER_BYTES), 'Plugin Name', 'Plugin URI');

            if ($headers['name'] !== null) {
                return [basename($candidate), $headers];
            }
        }

        return [null, $this->read('', 'Plugin Name', 'Plugin URI')];
    }

    /**
     * @return array{0: ?string, 1: array<string, string|null>}
     */
    private function themeHeader(ZipArchive $zip, string $slug): array
    {
        $headers = $this->read((string) $zip->getFromName("{$slug}/style.css", self::HEADER_BYTES), 'Theme Name', 'Theme URI');

        return [$headers['name'] === null ? null : 'style.css', $headers];
    }

    /**
     * get_file_data(), ported: a header is `Name: value` on its own line,
     * optionally behind comment punctuation, and ends at a closing comment.
     *
     * @return array<string, string|null>
     */
    private function read(string $contents, string $nameHeader, string $uriHeader): array
    {
        $contents = str_replace("\r", "\n", $contents);

        $fields = ['name' => $nameHeader, 'homepage' => $uriHeader, ...self::HEADERS];

        return array_map(function (string $header) use ($contents): ?string {
            if (preg_match('/^(?:[ \t]*<\?php)?[ \t\/*#@]*'.preg_quote($header, '/').':(.*)$/mi', $contents, $match) !== 1) {
                return null;
            }

            $value = trim((string) preg_replace('/\s*(?:\*\/|\?>).*/', '', $match[1]));

            return $value === '' ? null : $value;
        }, $fields);
    }
}
