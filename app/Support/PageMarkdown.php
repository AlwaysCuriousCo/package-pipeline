<?php

namespace App\Support;

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

/**
 * Renders the markdown behind a public page.
 *
 * The input is a README out of somebody else's repository, published by this
 * app at this app's origin, to anonymous visitors. That is the whole reason
 * this class exists rather than a call to Str::markdown():
 *
 *  - Raw HTML in the source is escaped, not passed through. A README is
 *    allowed to contain a `<script>` and frequently contains a `<div>`; on
 *    GitHub both are sanitized, and here the origin they would run at is the
 *    one holding the admin panel's session cookie. Escaping rather than
 *    stripping is deliberate: it is visible, so an admin can see that their
 *    HTML did not render, where stripping looks like the file was truncated.
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
            // The two settings this class exists for. `escape` renders raw
            // HTML as visible text; `allow_unsafe_links: false` drops
            // javascript:, vbscript: and non-image data: URLs.
            'html_input' => 'escape',
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

        return (new MarkdownConverter($environment))->convert($markdown)->getContent();
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
