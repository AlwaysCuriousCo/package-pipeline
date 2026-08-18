<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * An image from a package's repository, re-served by this registry.
 *
 * A README's screenshots are relative paths, and resolving them at the
 * provider works only for a repository the reader can already see. For a
 * private one, `raw.githubusercontent.com` answers 404 to anyone without a
 * GitHub credential — which is every visitor a public page exists for — so a
 * page's images would be broken exactly where the page matters most. This
 * registry holds the credential that can read the repository; it is the only
 * party in the exchange able to fetch the file at all.
 *
 * It is also what an og:image needs. Slack, X and every link unfurler fetch
 * that URL anonymously from their own infrastructure, so a card image living
 * in a private repository has to be served from somewhere they can reach.
 *
 * What keeps this from being an open proxy into private repositories:
 *
 *  - only packages that publish a page are reachable at all, and only through
 *    the repository mount that serves them;
 *  - only image extensions are served, so this cannot be used to read a
 *    repository's source, its .env.example or its CI configuration;
 *  - the path is confined to the repository — no scheme, no host, no `..`;
 *  - the ref is the page's own, not the caller's, so a URL cannot be edited
 *    into reading an unreleased branch;
 *  - responses are bounded in size and cached, so a page with a large image
 *    costs the provider one request rather than one per visitor.
 */
class PackageAssetController extends Controller
{
    /**
     * The extensions this will serve, and what it says they are.
     *
     * An allowlist rather than a guess at the provider's answer: the content
     * type is derived here from the path, so nothing a repository contains
     * can decide what this origin claims to be serving.
     *
     * @var array<string, string>
     */
    private const TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'ico' => 'image/x-icon',
        'svg' => 'image/svg+xml',
    ];

    public function __invoke(Request $request, string $vendor, string $package, string $path): Response
    {
        /** @var Repository $repository */
        $repository = $request->attributes->get('composerRepository');

        $found = $repository->packages()
            ->withPage()
            ->where('name', mb_strtolower("{$vendor}/{$package}"))
            ->first();

        abort_unless($found instanceof Package, 404, 'No package page is published at this address.');

        $type = $this->contentType($path);

        $contents = $this->contents($found, $this->confine($path));

        abort_if($contents === null, 404, 'That file is not in the package\'s repository.');

        return response($contents, 200, [
            'Content-Type' => $type,
            // The file is somebody else's, served from this origin, so it is
            // pinned to being an image and nothing else. `nosniff` stops a
            // browser second-guessing the type above, and the CSP is what
            // makes an SVG safe to serve inline: an SVG is a document, script
            // tags and all, and without this one committed to a repository
            // would run at the origin holding the panel's session cookie.
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            // Bounded rather than immutable: the URL names a path, and the
            // path's contents change when the package releases again.
            'Cache-Control' => 'public, max-age='.(int) config('registry.pages.asset_cache_minutes') * 60,
        ]);
    }

    /**
     * The type this path will be served as, or a 404 for anything that is not
     * an image.
     *
     * A 404 rather than a 415, deliberately: a caller probing this for a
     * repository's source files should learn nothing about what is there.
     */
    private function contentType(string $path): string
    {
        $extension = mb_strtolower((string) Str::afterLast($path, '.'));

        abort_unless(
            $extension !== $path && isset(self::TYPES[$extension]),
            404,
            'That file is not in the package\'s repository.',
        );

        return self::TYPES[$extension];
    }

    /**
     * The path, confined to the repository.
     *
     * A route parameter arrives decoded, so this is where `..` and a
     * backslash are refused rather than somewhere further down where they
     * would already have been interpolated into a provider's URL.
     */
    private function confine(string $path): string
    {
        $path = ltrim($path, '/');

        $segments = preg_split('#[\\\\/]+#', $path) ?: [];

        abort_if(
            in_array('..', $segments, true) || str_contains($path, "\0"),
            404,
            'That file is not in the package\'s repository.',
        );

        return $path;
    }

    /**
     * The file's bytes, from the cache or from the provider.
     *
     * Cached under the ref as well as the path, so a release that changes an
     * image publishes the new one without anything having to be cleared, and
     * a page whose screenshot has not changed costs the provider nothing.
     * `null` — a file that is not there — is cached too, and for the same
     * length: a README pointing at a moved image would otherwise spend a
     * provider request per visitor discovering that it is still missing.
     */
    private function contents(Package $package, string $path): ?string
    {
        $ref = $package->pageRef();

        $minutes = (int) config('registry.pages.asset_cache_minutes');

        $fetch = fn (): ?string => $this->fetch($package, $path, $ref);

        if ($minutes <= 0) {
            return $fetch();
        }

        $key = 'page-asset:'.$package->getKey().':'.hash('xxh128', $ref."\0".$path);

        // A miss is cached as `false` rather than as null, and that is not
        // fussiness: no cache store can tell a stored null from an absent
        // key, so remember() re-fetches a missing file on every request —
        // which is a provider request per visitor for a README that points at
        // an image somebody moved.
        $cached = Cache::get($key);

        if ($cached !== null) {
            return $cached === false ? null : (string) $cached;
        }

        $contents = $fetch();

        Cache::put($key, $contents ?? false, now()->addMinutes($minutes));

        return $contents;
    }

    /**
     * Read the file through the package's own credentials.
     *
     * The path travels repository-root-relative — the subdirectory is folded
     * into the URL the markdown was rewritten against — so the directory
     * handed to the client is empty and a monorepo package's images and a
     * root-relative link resolve through one route.
     *
     * A provider that errors is a missing image rather than a 500: the page
     * around it is still worth serving, and an alt text is a better answer
     * than an error page inside an <img>.
     */
    private function fetch(Package $package, string $path, ?string $ref): ?string
    {
        try {
            $contents = $package->client()->file($path, $ref);
        } catch (Throwable) {
            return null;
        }

        if ($contents === null) {
            return null;
        }

        $ceiling = max(0, (int) config('registry.pages.max_asset_kilobytes')) * 1024;

        // Refused rather than truncated: half an image is not an image, and
        // the bytes are already here — what the ceiling protects is the cache
        // and the memory of every later request, not this one's download.
        return $ceiling > 0 && strlen($contents) > $ceiling ? null : $contents;
    }
}
