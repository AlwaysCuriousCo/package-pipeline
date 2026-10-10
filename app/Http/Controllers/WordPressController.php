<?php

namespace App\Http\Controllers;

use App\Enums\Ecosystem;
use App\Enums\WordPressKind;
use App\Events\PackageDownloaded;
use App\Http\Middleware\AuthenticateWordPress;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Repository;
use App\Models\Token;
use App\Services\ArchiveStore;
use App\Services\Billing\VersionCeiling;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The subset of api.wordpress.org a site asks about the plugins and themes it
 * has installed: the update check, the information modal, and the zip itself.
 *
 * Answers only for packages this registry serves to WordPress (a
 * `wordpress_kind` and a slug); everything else is left out of the response
 * so wordpress.org can answer for it. The packages, visibility, tokens,
 * ceilings and download accounting are the Composer surface's — a WordPress
 * package is a Composer package with a second zip.
 *
 * Nothing in WordPress core calls these URLs on its own. A mu-plugin on the
 * site points the update and information filters here for the slugs it
 * serves; docs/wordpress.md gives it.
 *
 * @see AuthenticateWordPress
 * @see ComposerRepositoryController::dist() the archive serving this follows
 */
class WordPressController extends Controller
{
    public function __construct(
        private readonly ArchiveStore $archives,
        private readonly VersionCeiling $ceilings,
    ) {}

    /**
     * `POST /wp/plugins/update-check/1.1`, shaped as wp_update_plugins() sends
     * it: a `plugins` form field holding JSON `{"plugins": {"slug/file.php":
     * {"Version": ...}}, "active": [...]}`. A JSON body of the same object is
     * read as well.
     */
    public function pluginUpdateCheck(Request $request): JsonResponse
    {
        return $this->updateCheck($request, WordPressKind::Plugin, 'plugins');
    }

    /**
     * `POST /wp/themes/update-check/1.1`, shaped as wp_update_themes() sends
     * it: a `themes` form field holding JSON `{"themes": {"slug": {"Version":
     * ...}}, "active": "slug"}`.
     */
    public function themeUpdateCheck(Request $request): JsonResponse
    {
        return $this->updateCheck($request, WordPressKind::Theme, 'themes');
    }

    /**
     * `GET /wp/plugins/info/1.2?action=plugin_information&request[slug]=...`
     */
    public function pluginInformation(Request $request): JsonResponse
    {
        return $this->information($request, WordPressKind::Plugin, 'plugin_information');
    }

    /**
     * `GET /wp/themes/info/1.2?action=theme_information&request[slug]=...`
     */
    public function themeInformation(Request $request): JsonResponse
    {
        return $this->information($request, WordPressKind::Theme, 'theme_information');
    }

    /**
     * A version's WordPress zip, rooted at the slug: visibility first, the
     * ceiling, and the download counted only for a GET, as a Composer dist.
     *
     * Always streamed, never redirected to a signed disk URL. WordPress's HTTP
     * client re-sends the request's headers to wherever a redirect points, so
     * the site's bearer token would reach the storage service beside the
     * URL's own signature, and S3 refuses a request carrying two. ponytail:
     * pins a worker per download, fine at update and provisioning volume;
     * redirect to a signed URL if WordPress downloads ever dominate.
     */
    public function dist(Request $request, string $slug, string $version): StreamedResponse
    {
        $package = $this->servedPackages($request, slugs: [$slug])->first();

        abort_unless($package instanceof Package, 404, "No WordPress package is served as {$slug}.");

        $release = $package->versions()->where('version', $version)->whereNotNull('wordpress')->first();

        abort_unless($release instanceof PackageVersion, 404, "No WordPress zip is stored for {$slug} {$version}.");

        abort_unless(
            $this->ceilings->permits($release, $this->ceilings->ceilingFor($this->token($request), $package)),
            403,
            "Your subscription includes {$slug} up to the versions released while it was active; {$version} is newer.",
        );

        $path = (string) $release->wordpress['path'];

        abort_unless($this->archives->disk()->exists($path), 404, "No WordPress zip is stored for {$slug} {$version}; syncing the package will build it.");

        if ($request->isMethod('GET')) {
            PackageDownloaded::dispatch($package->id, $release->id, $release->version, $this->token($request)?->token_prefix);
        }

        return $this->archives->disk()->download($path, ArchiveStore::downloadFilename($slug, $release->version), [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    /**
     * The update check for one kind: an entry under the kind's key for each
     * served package with a newer release, one under `no_update` for each
     * served package already current, and nothing for anything else.
     */
    private function updateCheck(Request $request, WordPressKind $kind, string $field): JsonResponse
    {
        $payload = $request->input($field);
        $payload = is_string($payload) ? json_decode($payload, true) : $request->all();
        $installed = is_array($payload) && is_array($payload[$field] ?? null) ? $payload[$field] : [];

        // Keyed by what the site sent — `slug/file.php` for a plugin, the
        // stylesheet for a theme — because that is what core merges by.
        $slugs = [];

        foreach (array_keys($installed) as $key) {
            $slugs[(string) $key] = $kind === WordPressKind::Plugin
                ? Str::before(Str::beforeLast((string) $key, '.php'), '/')
                : (string) $key;
        }

        $packages = $this->servedPackages($request, $kind, array_values($slugs))->keyBy('wordpress_slug');

        $updates = [];
        $current = [];

        foreach ($slugs as $key => $slug) {
            $package = $packages->get($slug);
            $release = $package instanceof Package ? $this->release($request, $package) : null;

            if ($release === null) {
                continue;
            }

            $entry = $this->updateEntry($request, $package, $release, $key);
            $installedVersion = (string) ($installed[$key]['Version'] ?? '');

            if (version_compare($entry['new_version'], $installedVersion, '>')) {
                $updates[$key] = $entry;
            } else {
                $current[$key] = $entry;
            }
        }

        return response()->json([
            $field => (object) $updates,
            'no_update' => (object) $current,
            'translations' => [],
        ], 200, ['Cache-Control' => 'private, no-cache', 'Vary' => 'Authorization']);
    }

    /**
     * One package's entry in an update-check response.
     *
     * `new_version` is the header's version rather than the tag's: it is what
     * core compares against the installed header, and advertising the tag
     * when the two disagree is offering the same update forever.
     *
     * @return array<string, mixed>
     */
    private function updateEntry(Request $request, Package $package, PackageVersion $release, string $key): array
    {
        $header = $release->wordpress;
        $slug = (string) $package->wordpress_slug;

        $identity = $package->wordpress_kind === WordPressKind::Plugin
            ? ['id' => $slug, 'slug' => $slug, 'plugin' => $key]
            : ['theme' => $slug];

        return [
            ...$identity,
            'new_version' => (string) ($header['version'] ?? $release->version),
            'url' => (string) ($header['homepage'] ?? ''),
            'package' => $this->distUrl($request, $slug, $release),
            'requires' => $header['requires'] ?? null,
            'tested' => $header['tested'] ?? null,
            'requires_php' => $header['requires_php'] ?? null,
        ];
    }

    /**
     * The `*_information` answer for one slug.
     *
     * Errors here are wordpress.org's own shape, because the caller is
     * already asking about a slug it knows this registry serves; only the
     * authentication refusals are kept free of a parseable body.
     */
    private function information(Request $request, WordPressKind $kind, string $action): JsonResponse
    {
        if ($request->query('action', $action) !== $action) {
            return response()->json(['error' => 'Action not implemented.'], 400);
        }

        $slug = (string) data_get($request->query('request'), 'slug', '');
        $package = $slug === '' ? null : $this->servedPackages($request, $kind, [$slug])->first();
        $release = $package instanceof Package ? $this->release($request, $package) : null;

        if ($release === null) {
            return response()->json(['error' => ($kind === WordPressKind::Plugin ? 'Plugin' : 'Theme').' not found.'], 404);
        }

        $header = $release->wordpress;
        $author = (string) ($header['author'] ?? '');

        $sections = [
            'description' => '<p>'.e((string) ($header['description'] ?? $package->description)).'</p>',
        ];

        if (filled($header['changelog'] ?? null)) {
            $sections['changelog'] = Str::markdown((string) $header['changelog'], [
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]);
        }

        return response()->json([
            'name' => (string) ($header['name'] ?? $package->name),
            'slug' => $slug,
            'version' => (string) ($header['version'] ?? $release->version),
            // wordpress.org sends the author as a link, which the modal prints.
            'author' => filled($header['author_uri'] ?? null)
                ? '<a href="'.e((string) $header['author_uri']).'">'.e($author).'</a>'
                : e($author),
            'homepage' => (string) ($header['homepage'] ?? ''),
            'download_link' => $this->distUrl($request, $slug, $release),
            'requires' => $header['requires'] ?? null,
            'tested' => $header['tested'] ?? null,
            'requires_php' => $header['requires_php'] ?? null,
            'last_updated' => $release->released_at?->utc()->toIso8601String(),
            'sections' => $sections,
        ], 200, ['Cache-Control' => 'private, no-cache', 'Vary' => 'Authorization']);
    }

    /**
     * The release a site should be on: the highest stable version with a
     * WordPress zip that the token's ceiling permits, or null when there is
     * none to offer.
     */
    private function release(Request $request, Package $package): ?PackageVersion
    {
        $ceiling = $this->ceilings->ceilingFor($this->token($request), $package);

        return $package->versions()
            ->where('is_dev', false)
            ->whereNotNull('wordpress')
            ->orderedByVersion()
            ->get(['id', 'package_id', 'version', 'order', 'is_dev', 'released_at', 'wordpress'])
            ->first(fn (PackageVersion $version): bool => preg_match('/(alpha|beta|rc|dev)/i', $version->version) !== 1
                && $this->ceilings->permits($version, $ceiling));
    }

    /**
     * The served WordPress packages this request's principal may see, through
     * the mount it arrived on — narrowed to one kind and some slugs when given.
     *
     * @param  list<string>|null  $slugs
     * @return Collection<int, Package>
     */
    private function servedPackages(Request $request, ?WordPressKind $kind = null, ?array $slugs = null): Collection
    {
        $repository = $this->repository($request);

        return $repository->packages()
            ->ofEcosystem(Ecosystem::Composer)
            ->visibleTo($this->token($request), $repository)
            ->whereNotNull('wordpress_slug')
            ->when($kind !== null, fn ($query) => $query->where('wordpress_kind', $kind))
            ->when($slugs !== null, fn ($query) => $query->whereIn('wordpress_slug', $slugs))
            ->get();
    }

    private function distUrl(Request $request, string $slug, PackageVersion $release): string
    {
        return $this->repository($request)->url("/wp/dist/{$slug}/".rawurlencode($release->version).'.zip');
    }

    private function repository(Request $request): Repository
    {
        return $request->attributes->get('composerRepository');
    }

    private function token(Request $request): ?Token
    {
        return $request->attributes->get('composerToken');
    }
}
