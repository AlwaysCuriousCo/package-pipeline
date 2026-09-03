<?php

namespace App\Http\Controllers\Pages;

use App\Enums\Ecosystem;
use App\Enums\PageDownloads;
use App\Events\PackageDownloaded;
use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Repository;
use App\Services\ArchiveStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The download button on a public page.
 *
 * A third way to the same bytes, alongside the Composer dist endpoint and the
 * panel's own archive route, and it exists because the other two are no use
 * to the visitor this one is for: they have no token and no account, and they
 * are addressing the package by version rather than by commit reference.
 *
 * What it will hand over is decided entirely on the package row:
 *
 *  - the page must be enabled, or nothing here exists;
 *  - the repository must be public, or Package::pageDownloads() reads as None
 *    however the package was configured — an archive is the package's content
 *    and a private repository exists to refuse it to anonymous callers;
 *  - "latest release only" means exactly that: any other version is a 404,
 *    so a page offering one current artifact cannot be walked backwards
 *    through the release history by editing the URL.
 *
 * Counted like every other archive that leaves the registry, so the download
 * numbers mean one thing across all three routes.
 */
class PackageArchiveController extends Controller
{
    public function __construct(private readonly ArchiveStore $archives) {}

    public function __invoke(
        Request $request,
        string $vendor,
        string $package,
        ?string $version = null,
    ): StreamedResponse|RedirectResponse {
        /** @var Repository $repository */
        $repository = $request->attributes->get('composerRepository');

        $found = $repository->packages()
            ->withPage()
            ->where('name', mb_strtolower("{$vendor}/{$package}"))
            ->with('composerRepository')
            ->first();

        abort_unless($found instanceof Package, 404, 'No package page is published at this address.');

        // Through this mount, not through wherever the package lives: a
        // private repository serving a package that is public elsewhere still
        // offers nothing to an anonymous visitor. @see Package::pageDownloads()
        $offered = $found->servedFrom($repository)->pageDownloads();

        abort_if(
            $offered === PageDownloads::None,
            404,
            "The page for {$found->name} does not offer downloads.",
        );

        $release = $this->release($found, $offered, $version);

        $disk = $this->archives->disk();

        // A row's path can outlive its file — storage lost on a redeploy, an
        // object deleted out from under us. One metadata call against a
        // transfer orders of magnitude larger, exactly as the dist endpoint
        // and the panel's route both spend it.
        abort_unless(
            $release->archive_path !== null && $disk->exists($release->archive_path),
            404,
            "No archive is stored for {$found->name}@{$release->version}.",
        );

        // An npm version is stored as the tarball `npm publish` sent, and a
        // Composer one as the zip Composer expects; a page never serves a
        // Python project (@see Package::hasPage()).
        $npm = $found->ecosystem === Ecosystem::Npm;

        $filename = ArchiveStore::downloadFilename($found->name, $release->version, $npm ? 'tgz' : 'zip');

        // Only an archive actually being served counts, and only a GET:
        // Laravel answers HEAD on every GET route and drops the body far below
        // this line, so a link checker or a social platform fetching the page's
        // links would otherwise land in total_downloads as a download that
        // took no bytes.
        //
        // No token prefix, because none was presented — this request carried
        // no credential at all, which is what a public page means.
        if ($request->isMethod('GET')) {
            PackageDownloaded::dispatch($found->id, $release->id, $release->version, null);
        }

        $url = $this->archives->temporaryUrl($release->archive_path, $filename);

        if ($url !== null) {
            // The archive is immutable; this response is not — it carries a
            // URL that stops working in minutes.
            return redirect()->away($url, headers: ['Cache-Control' => 'no-store']);
        }

        return $disk->download($release->archive_path, $filename, [
            'Content-Type' => $npm ? 'application/gzip' : 'application/zip',
            // Public bytes at a stable address, so a shared cache is welcome
            // to keep them: a version's archive never changes, and the URL
            // names the version rather than a row id.
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * The version this URL addresses.
     *
     * No version segment means the current release, which is the link the
     * page's own button carries — a URL that goes on meaning "the latest" as
     * releases come and go, and therefore the one worth putting in a
     * changelog or a chat message.
     */
    private function release(Package $package, PageDownloads $offered, ?string $version): PackageVersion
    {
        if ($version === null) {
            $latest = $package->pageLatestVersion();

            abort_unless(
                $latest instanceof PackageVersion,
                404,
                "{$package->name} has no released version to download.",
            );

            return $latest;
        }

        abort_if(
            $offered !== PageDownloads::All,
            404,
            "The page for {$package->name} offers its latest release only.",
        );

        $release = $package->versions()->where('version', $version)->first();

        abort_unless(
            $release instanceof PackageVersion,
            404,
            "{$package->name} has no version {$version}.",
        );

        return $release;
    }
}
