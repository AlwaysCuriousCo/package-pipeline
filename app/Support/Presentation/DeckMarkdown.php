<?php

namespace App\Support\Presentation;

/**
 * Splits a deck written for Marp or Slidev — or SlideWire itself — into
 * slides, without rendering any of it.
 *
 * Deliberately a subset of all three, chosen so that a deck written for any
 * of them comes out as the same slides:
 *
 *  - `---` on its own line separates slides, as does SlideWire's
 *    `<!-- slide -->`. A `---` straight after a line of text is left alone:
 *    CommonMark reads that as a heading underline, and so does Marp.
 *  - A leading frontmatter block is the deck's settings in all three, and is
 *    dropped: how a deck looks is decided in the panel.
 *  - Per-slide frontmatter (Slidev's `---\nlayout: center\n---`) and Marp's
 *    directive comments (`<!-- _backgroundImage: url(x) -->`) are read for a
 *    background and a transition and nothing else. `![bg](x)` is Marp's other
 *    spelling of a background.
 *  - Every other HTML comment is a speaker note — Marp's rule, and Slidev's
 *    for a comment closing a slide. Notes are returned apart from the body so
 *    they can never reach a visitor by accident.
 *
 * Anything else a dialect means — Vue components, layouts, themes — is
 * ignored and left to render as the markdown it is.
 */
class DeckMarkdown
{
    /**
     * The only per-slide settings honoured, under SlideWire's names.
     */
    public const SLIDE_KEYS = ['background', 'transition'];

    /**
     * Keys a per-slide frontmatter block may contain for the block to be read
     * as one. Restricted so that a slide whose only line is "Note: bring
     * snacks" stays a slide rather than becoming settings.
     */
    private const FRONTMATTER_KEYS = [
        'background', 'transition', 'layout', 'class', 'title', 'theme', 'image',
        'clicks', 'level', 'hide', 'disabled', 'preload', 'routeAlias', 'src',
        'backgroundImage', 'backgroundColor', 'paginate', 'header', 'footer',
    ];

    /**
     * @return list<array{meta: array<string, string>, body: string, notes: list<string>}>
     */
    public function split(string $markdown): array
    {
        $lines = explode("\n", $this->withoutDeckFrontmatter(str_replace(["\r\n", "\r"], "\n", ltrim($markdown, "\u{FEFF}"))));

        $slides = [];
        $buffer = [];
        $meta = [];
        $fence = null;

        // Whether the current slide began at a separator and has, so far,
        // only key: value lines — which a closing `---` turns into its
        // frontmatter rather than into a slide of its own.
        $fresh = false;

        foreach ($lines as $line) {
            $fence = $this->fenceAfter($line, $fence);

            if ($fence !== null || $this->isFenceLine($line)) {
                $buffer[] = $line;
                $fresh = false;

                continue;
            }

            $trimmed = trim($line);
            $previous = $buffer === [] ? '' : trim((string) end($buffer));

            if ($trimmed === '---' && $fresh && $previous !== '' && $this->isFrontmatter($buffer)) {
                $meta = $this->frontmatter($buffer);
                $buffer = [];
                $fresh = false;

                continue;
            }

            // After a blank line or a `#` heading, `---` can only be a break;
            // after a line of text it is that text's heading underline.
            if ($trimmed === '<!-- slide -->' || ($trimmed === '---' && ($previous === '' || str_starts_with($previous, '#')))) {
                $slides[] = $this->slide($buffer, $meta);
                $buffer = [];
                $meta = [];
                $fresh = true;

                continue;
            }

            $buffer[] = $line;
        }

        $slides[] = $this->slide($buffer, $meta);

        // A slide that is only a background is a picture slide, not an empty one.
        return array_values(array_filter($slides, fn (array $slide): bool => $slide['body'] !== '' || isset($slide['meta']['background'])));
    }

    /**
     * Lift a slide's fenced code blocks out of its markdown, leaving a
     * paragraph of "{$token}{n}" in each one's place — so the code can be
     * highlighted on its own, and the markdown around it rendered without it.
     *
     * @return array{0: string, 1: list<array{language: string, code: string}>}
     */
    public function extractFences(string $markdown, string $token): array
    {
        $out = [];
        $blocks = [];
        $fence = null;
        $language = '';
        $code = [];

        foreach (explode("\n", $markdown) as $line) {
            $opening = $fence === null && $this->isFenceLine($line);
            $wasInside = $fence !== null;
            $fence = $this->fenceAfter($line, $fence);

            if ($opening) {
                $language = (string) preg_replace('/[^\w+#-].*$/', '', trim((string) preg_replace('/^ {0,3}(`{3,}|~{3,})/', '', $line)));
                $code = [];

                continue;
            }

            if ($wasInside && $fence === null) {
                $out[] = "\n{$token}".count($blocks)."\n";
                $blocks[] = ['language' => $language === '' ? 'text' : $language, 'code' => implode("\n", $code)];

                continue;
            }

            if ($wasInside) {
                $code[] = $line;

                continue;
            }

            $out[] = $line;
        }

        // A fence never closed runs to the end of the slide, as CommonMark has it.
        if ($fence !== null) {
            $out[] = "\n{$token}".count($blocks)."\n";
            $blocks[] = ['language' => $language === '' ? 'text' : $language, 'code' => implode("\n", $code)];
        }

        return [implode("\n", $out), $blocks];
    }

    /**
     * Drop a frontmatter block at the very top of the document.
     */
    private function withoutDeckFrontmatter(string $markdown): string
    {
        if (preg_match('/\A\s*---\n(.*?)\n---[ \t]*(\n|\z)/s', $markdown, $match) !== 1) {
            return $markdown;
        }

        return substr($markdown, strlen($match[0]));
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, string>  $meta
     * @return array{meta: array<string, string>, body: string, notes: list<string>}
     */
    private function slide(array $lines, array $meta): array
    {
        $notes = [];
        $body = $this->outsideFences(implode("\n", $lines), function (string $text) use (&$meta, &$notes): string {
            $text = (string) preg_replace_callback('/<!--(.*?)-->/s', function (array $match) use (&$meta, &$notes): string {
                $comment = trim($match[1]);

                if (preg_match('/^_?([A-Za-z]+)\s*:\s*(.+)$/', $comment, $directive) === 1 && ! str_contains($comment, "\n")) {
                    $this->directive($directive[1], trim($directive[2]), $meta);

                    return '';
                }

                if ($comment !== '') {
                    $notes[] = $comment;
                }

                return '';
            }, $text);

            // Marp's background image, written as an image whose alt text
            // starts with "bg". Taken out of the body: it is not content.
            return (string) preg_replace_callback('/!\[bg(?:\s[^\]]*)?\]\(\s*<?([^)\s>]+)>?[^)]*\)/', function (array $match) use (&$meta): string {
                $meta['background'] ??= $match[1];

                return '';
            }, $text);
        });

        return ['meta' => $meta, 'body' => trim($body), 'notes' => $notes];
    }

    /**
     * A Marp directive, for the two settings honoured. `url(...)` is how Marp
     * writes an image, and the quotes inside it are optional.
     *
     * @param  array<string, string>  $meta
     */
    private function directive(string $key, string $value, array &$meta): void
    {
        if ($key === 'backgroundImage' && preg_match('/^url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)$/', $value, $url) === 1) {
            $meta['background'] = $url[1];
        }

        if ($key === 'transition') {
            $meta['transition'] = $value;
        }
    }

    /**
     * @param  list<string>  $lines
     */
    private function isFrontmatter(array $lines): bool
    {
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            if (preg_match('/^([A-Za-z][A-Za-z0-9_-]*):(\s.*)?$/', $line, $match) !== 1
                || ! in_array($match[1], self::FRONTMATTER_KEYS, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, string>
     */
    private function frontmatter(array $lines): array
    {
        $meta = [];

        foreach ($lines as $line) {
            if (preg_match('/^([A-Za-z]+):\s*(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $value = trim($match[2], " \t\"'");

            if (in_array($match[1], self::SLIDE_KEYS, true) && $value !== '') {
                $meta[$match[1]] = $value;
            }
        }

        return $meta;
    }

    /**
     * Apply $transform to the text between fenced code blocks only, so a
     * comment or a `![bg]` quoted inside a code sample stays as written.
     *
     * @param  callable(string): string  $transform
     */
    private function outsideFences(string $text, callable $transform): string
    {
        $out = [];
        $chunk = [];
        $fence = null;

        foreach (explode("\n", $text) as $line) {
            $opening = $fence === null && $this->isFenceLine($line);
            $wasInside = $fence !== null;
            $fence = $this->fenceAfter($line, $fence);

            if ($opening || $wasInside) {
                if ($chunk !== []) {
                    $out[] = $transform(implode("\n", $chunk));
                    $chunk = [];
                }

                $out[] = $line;

                continue;
            }

            $chunk[] = $line;
        }

        if ($chunk !== []) {
            $out[] = $transform(implode("\n", $chunk));
        }

        return implode("\n", $out);
    }

    private function isFenceLine(string $line): bool
    {
        return preg_match('/^ {0,3}(`{3,}|~{3,})/', $line) === 1;
    }

    /**
     * The fence still open after this line: the one it opens, null if it
     * closes the one that was open, or the same one if it is inside it.
     */
    private function fenceAfter(string $line, ?string $open): ?string
    {
        if (preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/', $line, $match) !== 1) {
            return $open;
        }

        if ($open === null) {
            return $match[1];
        }

        // A fence closes on a run of the same character at least as long,
        // with nothing after it.
        return $match[1][0] === $open[0] && strlen($match[1]) >= strlen($open) && trim($match[2]) === ''
            ? null
            : $open;
    }
}
