<?php

namespace App\Services;

use App\Models\Package;
use App\Models\Repository;
use App\Support\PageMarkdown;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;
use Throwable;

/**
 * The body of a public page: where it comes from and what it renders to.
 *
 * Two jobs that belong together because they are two halves of one decision —
 * a page's markdown is read from the repository at sync time and rendered at
 * request time, and both need to agree about which file won and how big is
 * too big.
 *
 * Reading is deliberately not done per request. A page is served to anonymous
 * visitors, so a fetch on the render path would put somebody else's traffic
 * in front of this installation's GitHub rate limit and make the page's
 * latency depend on a third party being up. So the markdown is stored on the
 * package and refreshed when something happens that could have changed it: a
 * sync, or an admin switching the page on.
 */
class PackagePage
{
    public function __construct(private readonly PageMarkdown $markdown = new PageMarkdown) {}

    /**
     * Read this package's page markdown out of its repository and store it.
     *
     * Never throws: this runs inside a sync, and a README that could not be
     * read is not a reason to fail the sync that published the release. It is
     * a page that shows what it had last time — or no body at all, which the
     * page renders as the package's metadata alone.
     *
     * @param  string|null  $ref  the ref to read at, or null to read at the
     *                            release the package's own metadata came from
     * @return bool whether a body was found
     */
    public function refresh(Package $package, ?string $ref = null): bool
    {
        if (blank($package->repository) || $package->pageBodyCandidates() === []) {
            return false;
        }

        try {
            $client = $package->client();

            // So that a refresh asked for outside a sync — the panel action,
            // the job that runs when a page is switched on — stores what the
            // next sync would store. Reading the default branch instead would
            // publish a document describing a version that has not shipped,
            // and would be reverted by the next sync anyway.
            $ref ??= $package->pageRef();

            foreach ($package->pageBodyCandidates() as $path) {
                $body = $client->file($path, $ref, (string) $package->subdirectory);

                if ($body === null || trim($body) === '') {
                    continue;
                }

                $package->recordBookkeeping([
                    'page_source_body' => $this->bounded($body),
                    'page_source_path' => $path,
                    'page_source_synced_at' => now(),
                ]);

                return true;
            }

            // Nothing found is a result, not a failure — and it is recorded as
            // one. Without the timestamp, every later sync would walk the
            // whole candidate list again against a repository that has none of
            // them, which on GitHub is five requests per package per sync.
            $package->recordBookkeeping([
                'page_source_body' => null,
                'page_source_path' => null,
                'page_source_synced_at' => now(),
            ]);

            return false;
        } catch (Throwable $exception) {
            // Logged rather than surfaced: the sync's own `sync_error` column
            // is about whether this registry can serve the package, and a
            // missing README is not that.
            Log::warning('Could not read the page content for a package.', [
                'package' => $package->name,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The rendered HTML for a package's page body, or null when it has none.
     *
     * Cached on a key derived from the markdown itself, so a body that
     * changes renders again and a body that has not is never re-parsed. The
     * bases are in the key too: moving a package to a different repository
     * URL changes where its relative links point.
     */
    public function render(Package $package): ?HtmlString
    {
        ['body' => $body] = $package->pageContent();

        if ($body === null) {
            return null;
        }

        return $this->cached(
            $body,
            $package->pageLinkBase(),
            $package->pageImageBase(),
            $package->pageLinkRootBase(),
            $package->pageImageRootBase(),
        );
    }

    /**
     * The same for a repository's landing page, whose body is only ever
     * written in the panel — so there is no repository to resolve relative
     * links against, and a relative link is dropped.
     */
    public function renderRepository(Repository $repository): ?HtmlString
    {
        $body = (string) $repository->page_body;

        return trim($body) === '' ? null : $this->cached($body, null, null);
    }

    /**
     * Render, through the cache.
     */
    private function cached(
        string $body,
        ?string $linkBase,
        ?string $imageBase,
        ?string $linkRootBase = null,
        ?string $imageRootBase = null,
    ): HtmlString {
        $body = $this->bounded($body);

        $minutes = (int) config('registry.pages.markdown_cache_minutes');

        $render = fn (): string => $this->markdown->render($body, $linkBase, $imageBase, $linkRootBase, $imageRootBase);

        if ($minutes <= 0) {
            return new HtmlString($render());
        }

        $key = 'page-markdown:'.hash('xxh128', implode("\0", [$body, $linkBase, $imageBase, $linkRootBase, $imageRootBase]));

        return new HtmlString(Cache::remember($key, now()->addMinutes($minutes), $render));
    }

    /**
     * The body, cut to the configured ceiling.
     *
     * Cut on a line rather than mid-character, and marked, because a page that
     * silently stops halfway through a sentence reads as a bug in this app
     * rather than as a file that was too long to publish.
     */
    private function bounded(string $body): string
    {
        $limit = max(0, (int) config('registry.pages.max_body_kilobytes')) * 1024;

        if ($limit === 0 || strlen($body) <= $limit) {
            return $body;
        }

        $cut = substr($body, 0, $limit);
        $lastBreak = strrpos($cut, "\n");

        if ($lastBreak !== false) {
            $cut = substr($cut, 0, $lastBreak);
        }

        return $cut."\n\n*This document was truncated.*\n";
    }
}
