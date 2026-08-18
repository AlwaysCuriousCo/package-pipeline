<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Repository;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Response as ResponseFactory;

/**
 * /sitemap.xml and /robots.txt, listing exactly the pages this registry has
 * been told to publish.
 *
 * A page nobody links to is a page nobody finds, and a private registry's
 * pages are usually linked from nowhere at all — so without this the feature
 * is a URL to paste into chat and nothing more. With it, a public repository's
 * packages are discoverable the ordinary way.
 *
 * Which is also why it is a setting rather than a given: an installation whose
 * pages are for people who were sent the link should not be advertising the
 * list of them. Off, both documents still answer — robots.txt with a blanket
 * disallow, the sitemap with an empty index — because a 404 on robots.txt is
 * read by some crawlers as permission to crawl everything.
 */
class SitemapController extends Controller
{
    /**
     * How many URLs one sitemap may name. The protocol's own ceiling is
     * 50,000; a registry past that wants a sitemap index, and until one exists
     * naming the first ten thousand is the honest limit — see the comment in
     * the loop.
     */
    private const MAX_URLS = 10000;

    public function sitemap(): Response
    {
        $urls = $this->enabled() ? $this->urls() : [];

        $xml = ['<?xml version="1.0" encoding="UTF-8"?>'];
        $xml[] = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($urls as $url) {
            $xml[] = '  <url>';
            $xml[] = '    <loc>'.htmlspecialchars($url['loc'], ENT_XML1).'</loc>';

            if ($url['lastmod'] !== null) {
                $xml[] = '    <lastmod>'.$url['lastmod'].'</lastmod>';
            }

            $xml[] = '  </url>';
        }

        $xml[] = '</urlset>';

        return ResponseFactory::make(implode("\n", $xml)."\n", 200, [
            'Content-Type' => 'application/xml',
            // A crawler is not a visitor: an hour of staleness costs nothing
            // and spares the registry a full scan per crawl.
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function robots(): Response
    {
        $lines = $this->enabled()
            ? [
                'User-agent: *',
                // The panel, the Composer API and the download routes are all
                // things a crawler can do nothing useful with — and the
                // download routes it can do something actively unwanted with,
                // namely spend the registry's bandwidth and inflate every
                // package's download count.
                'Disallow: /admin',
                'Disallow: /p2/',
                'Disallow: /dist/',
                // A page's own download button, under both mounts. The
                // wildcards are RFC 9309's, and they are spelled out to the
                // vendor and package segments rather than as a bare
                // `/*/download` so that a package actually named "download"
                // keeps its page indexed. A longer match wins over the
                // `Allow: /` below, which is why these come first.
                'Disallow: /p/*/*/download',
                'Disallow: /r/*/p/*/*/download',
                'Allow: /',
                '',
                'Sitemap: '.Repository::default()->pageRootUrl().'/sitemap.xml',
            ]
            : [
                'User-agent: *',
                'Disallow: /',
            ];

        return ResponseFactory::make(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function enabled(): bool
    {
        return (bool) config('registry.pages.sitemap');
    }

    /**
     * Every page this registry publishes: the repositories with a landing
     * page, and the packages with one.
     *
     * A package in a private repository is listed too, and deliberately: its
     * page exists, says so, and is where somebody asks for access. What it
     * will not do is hand out an archive — see PackageArchiveController.
     *
     * @return list<array{loc: string, lastmod: ?string}>
     */
    private function urls(): array
    {
        $urls = [];

        foreach (Repository::query()->where('page_enabled', true)->get() as $repository) {
            $urls[] = ['loc' => $repository->pageUrl(), 'lastmod' => $repository->updated_at?->toAtomString()];
        }

        Package::query()
            ->withPage()
            ->with('composerRepository')
            ->orderBy('id')
            // Chunked because this is one query over a table that is allowed
            // to be enormous, answered for an anonymous request.
            ->limit(self::MAX_URLS)
            ->each(function (Package $package) use (&$urls): void {
                $urls[] = [
                    'loc' => $package->pageUrl(),
                    // What a crawler asks this for is "has the thing at that
                    // URL changed", and for a package page the answer is a
                    // release or an edit — not the hourly sync that writes
                    // `last_synced_at` whether or not anything moved.
                    'lastmod' => $package->updated_at?->toAtomString(),
                ];
            });

        return $urls;
    }
}
