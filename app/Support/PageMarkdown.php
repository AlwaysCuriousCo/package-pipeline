<?php

namespace App\Support;

use Dom\HTMLDocument;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\ExternalLink\ExternalLinkExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TaskList\TaskListExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\Document;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Renders the markdown behind a public page.
 *
 * The input is a README out of somebody else's repository, published by this
 * app at this app's origin, to anonymous visitors. That is the whole reason
 * this class exists rather than a call to Str::markdown():
 *
 *  - Raw HTML in the source is sanitized, not trusted and not escaped. A
 *    README is allowed to contain a `<script>` and frequently contains the
 *    `<div align="center"><picture>` header every Laravel package ships; the
 *    origin a script would run at is the one holding the admin panel's
 *    session cookie, so the markup goes through Symfony's HTML sanitizer on
 *    its safe-elements profile — the same bargain GitHub makes.
 *  - `javascript:` and `data:` URLs are refused by the converter.
 *  - Relative links and images — which is how every README references its own
 *    screenshots — are resolved against the repository they came from, so
 *    they point at the file on GitHub rather than at a 404 here.
 *  - External links carry rel="nofollow noopener ugc": the content is not
 *    ours, and the registry should not be lending its ranking to whatever a
 *    package's README links to.
 *
 * Rendering is not cached here. The caller caches, because only the caller
 * knows what invalidates it — see App\Services\PackagePage.
 */
class PageMarkdown
{
    /**
     * How deep a document may nest before the parser gives up, which bounds
     * what a pathological README costs to render. CommonMark's own default;
     * restated because it is a limit this surface depends on rather than a
     * detail of the library.
     */
    private const MAX_NESTING_LEVEL = 500;

    /**
     * @param  string|null  $linkBase  absolute URL a relative link resolves
     *                                 against — the repository's file browser
     *                                 — or null to drop relative links.
     * @param  string|null  $imageBase  absolute URL a relative image resolves
     *                                  against. Separate from $linkBase because
     *                                  providers serve the file and a view of
     *                                  the file at different hosts: an <img>
     *                                  wants raw bytes, an <a> wants the page.
     * @param  string|null  $linkRootBase  what a link written with a leading
     *                                     slash resolves against — the
     *                                     repository root, which for a
     *                                     monorepo package is not the same
     *                                     place as $linkBase. Null means the
     *                                     two are the same.
     * @param  string|null  $imageRootBase  the same, for images.
     */
    public function render(
        string $markdown,
        ?string $linkBase = null,
        ?string $imageBase = null,
        ?string $linkRootBase = null,
        ?string $imageRootBase = null,
    ): string {
        $environment = new Environment([
            // Raw HTML passes the parser and is sanitized afterwards, on
            // the whole document, so markup spanning several blocks — the
            // `<div>` wrapping half a README — is judged in one piece.
            // `allow_unsafe_links: false` drops javascript:, vbscript: and
            // non-image data: URLs.
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
            'max_nesting_level' => self::MAX_NESTING_LEVEL,
            'external_link' => [
                // Every link is external here — the document did not come
                // from this host, so nothing in it is a link home.
                'internal_hosts' => [],
                'open_in_new_window' => true,
                'html_class' => 'external',
                'nofollow' => 'external',
                'noopener' => 'all',
                'noreferrer' => 'all',
            ],
        ]);

        $environment
            ->addExtension(new CommonMarkCoreExtension)
            // A README is written for GitHub, so it is read as GitHub reads
            // it: tables, task lists, strikethrough and bare URLs.
            ->addExtension(new TableExtension)
            ->addExtension(new TaskListExtension)
            ->addExtension(new StrikethroughExtension)
            ->addExtension(new AutolinkExtension)
            ->addExtension(new ExternalLinkExtension);

        // Runs before rendering, on the parsed tree, so the URLs the external
        // link extension then judges are the absolute ones this resolves to.
        $environment->addEventListener(
            DocumentParsedEvent::class,
            fn (DocumentParsedEvent $event) => $this->resolveRelativeUrls(
                $event->getDocument(),
                $linkBase,
                $imageBase,
                $linkRootBase ?? $linkBase,
                $imageRootBase ?? $imageBase,
            ),
            // A higher priority than the external-link extension's own
            // listener (0), which decides what is external by looking at the
            // host — and a relative URL has none until this has run.
            priority: 10,
        );

        $html = (new MarkdownConverter($environment))->convert($markdown)->getContent();

        return $this->finishAnchorsAndUrls(
            $this->sanitize($html),
            $linkBase,
            $imageBase,
            $linkRootBase ?? $linkBase,
            $imageRootBase ?? $imageBase,
        );
    }

    /**
     * Strip everything from the rendered document that a README has no
     * business carrying at this origin.
     *
     * Safe elements is Symfony's own list: no script, no style, no iframe,
     * no event handlers, no CSS that can reach outside its own box.
     */
    private function sanitize(string $html): string
    {
        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            // The one element the safe list withholds that a README needs:
            // a task list is rendered as disabled checkboxes, and without
            // this the list arrives with its boxes missing. Harmless on its
            // own — `form` is not allowed, so there is nothing to submit to.
            ->allowElement('input', ['type', 'checked', 'disabled'])
            // Relative URLs survive the sanitizer so that finishAnchorsAndUrls()
            // can point them at the repository; what it cannot resolve it
            // empties itself.
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowMediaSchemes(['http', 'https']);

        return (new HtmlSanitizer($config))->sanitizeFor('body', $html);
    }

    /**
     * Finish the raw HTML the sanitizer let through: resolve the URLs in it,
     * and give its anchors the rel the parsed ones already carry.
     *
     * The tree pass below only sees links and images the markdown parser
     * made; a README's `<img src="art/header.png">` is one string to the
     * parser, and an `<a>` written as HTML never reaches the external-link
     * extension. Both are finished here, on the parsed document rather than
     * on its text, so that a path quoted inside a code sample stays the text
     * the author wrote.
     *
     * Fragment links point within this page, so they are left alone; every
     * other anchor has its rel and target replaced, including one that came
     * with its own.
     */
    private function finishAnchorsAndUrls(
        string $html,
        ?string $linkBase,
        ?string $imageBase,
        ?string $linkRootBase,
        ?string $imageRootBase,
    ): string {
        if (trim($html) === '') {
            return $html;
        }

        $document = HTMLDocument::createFromString(
            '<!DOCTYPE html><body>'.$html.'</body>',
            LIBXML_NOERROR,
            'UTF-8',
        );

        foreach ($document->body->querySelectorAll('[href], [src], [srcset]') as $element) {
            foreach (['href', 'src', 'srcset'] as $attribute) {
                if (! $element->hasAttribute($attribute)) {
                    continue;
                }

                $element->setAttribute($attribute, $this->resolveAttribute(
                    $element->getAttribute($attribute),
                    list: $attribute === 'srcset',
                    media: $attribute !== 'href',
                    linkBase: $linkBase,
                    imageBase: $imageBase,
                    linkRootBase: $linkRootBase,
                    imageRootBase: $imageRootBase,
                ));
            }
        }

        foreach ($document->body->querySelectorAll('a[href]') as $anchor) {
            // A link into this page is not outbound and is left as it is.
            // Everything else is overwritten rather than topped up: the rel
            // a README wrote is the author's opinion of how this registry
            // should vouch for their links, and a parsed link is only being
            // given back the values it already carries.
            if (str_starts_with($anchor->getAttribute('href'), '#')) {
                continue;
            }

            $anchor->setAttribute('rel', 'external nofollow noopener noreferrer');
            $anchor->setAttribute('target', '_blank');
        }

        return $document->body->innerHTML;
    }

    /**
     * Resolve one URL-bearing attribute value.
     *
     * srcset is a comma-separated list, each entry a URL and an optional
     * density or width descriptor; everything else is one URL, which may
     * itself contain a comma.
     */
    private function resolveAttribute(
        string $value,
        bool $list,
        bool $media,
        ?string $linkBase,
        ?string $imageBase,
        ?string $linkRootBase,
        ?string $imageRootBase,
    ): string {
        $resolved = array_map(function (string $part) use ($media, $linkBase, $imageBase, $linkRootBase, $imageRootBase): string {
            $trimmed = trim($part);
            [$url, $descriptor] = array_pad(preg_split('/\s+/', $trimmed, 2) ?: [], 2, '');

            $url = (string) $url;

            if ($url === '' || str_starts_with($url, '#') || $this->isAbsolute($url)) {
                return $trimmed;
            }

            $root = str_starts_with($url, '/');
            $base = $media
                ? ($root ? $imageRootBase : $imageBase)
                : ($root ? $linkRootBase : $linkBase);

            return trim(($base === null ? '' : $this->join($base, $url)).' '.$descriptor);
        }, $list ? explode(',', $value) : [$value]);

        return implode(', ', $resolved);
    }

    /**
     * Point every relative link and image at the repository it was written
     * against.
     *
     * A URL that cannot be resolved — because no base was given, as for a
     * body typed in the panel or a package with no repository — is emptied
     * rather than left relative. Left alone it would address this app: a
     * README's `docs/install.md` becomes a link to a page on the registry
     * that does not exist, and `![](logo.png)` becomes a broken image that
     * costs a request and a 404 log line on every page view.
     */
    private function resolveRelativeUrls(
        Document $document,
        ?string $linkBase,
        ?string $imageBase,
        ?string $linkRootBase,
        ?string $imageRootBase,
    ): void {
        foreach ($document->iterator() as $node) {
            if (! $node instanceof Link && ! $node instanceof Image) {
                continue;
            }

            $url = $node->getUrl();

            // A fragment is the one relative form that means something here:
            // README anchors link within the rendered document itself.
            if ($url === '' || str_starts_with($url, '#')) {
                continue;
            }

            if ($this->isAbsolute($url)) {
                continue;
            }

            // A leading slash is the one relative form that is not relative
            // to the document: it means the repository root, so it resolves
            // against the root base rather than the package's own directory.
            $root = str_starts_with($url, '/');

            $base = $node instanceof Image
                ? ($root ? $imageRootBase : $imageBase)
                : ($root ? $linkRootBase : $linkBase);

            $node->setUrl($base === null ? '' : $this->join($base, $url));
        }
    }

    /**
     * Whether a URL already names where it points. Protocol-relative URLs
     * ("//host/path") count: they resolve against the page's own scheme, not
     * against the repository.
     */
    private function isAbsolute(string $url): bool
    {
        return str_starts_with($url, '//') || (bool) preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url);
    }

    /**
     * Hang a repository-relative path off a base URL.
     *
     * Only the path is joined. A README that reaches out of its own
     * repository with "../" is left pointing at whatever that resolves to on
     * the provider, which is the provider's business — but the leading "./"
     * and "/" forms are normalized here, because a leading slash in a README
     * means the repository root and would otherwise escape the base entirely.
     */
    private function join(string $base, string $url): string
    {
        // Anything after the path is the author's, not ours to re-encode:
        // "?raw=true" and "#heading" both survive verbatim.
        [$path, $suffix] = $this->splitSuffix($url);

        $path = ltrim($path, '/');

        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        // Each segment individually, since a path is not one component — the
        // same reason the provider clients encode composer.json's path that
        // way. Already-encoded segments are left as they are, or "%20" would
        // travel as "%2520".
        $encoded = implode('/', array_map(
            fn (string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', $path),
        ));

        return rtrim($base, '/').'/'.$encoded.$suffix;
    }

    /**
     * The path, and the query string and fragment trailing it.
     *
     * @return array{string, string}
     */
    private function splitSuffix(string $url): array
    {
        $cut = strcspn($url, '?#');

        return [substr($url, 0, $cut), substr($url, $cut)];
    }
}
