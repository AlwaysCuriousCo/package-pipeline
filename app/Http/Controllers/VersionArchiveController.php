<?php

namespace App\Http\Controllers;

use App\Events\PackageDownloaded;
use App\Filament\Resources\Packages\Actions\DownloadArchiveAction;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use App\Services\ArchiveStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hands the signed-in admin the stored zip for one package version.
 *
 * The panel already shows what a version declares; this is the rest of that
 * question — the bytes Composer would actually install — for the times when
 * reading the manifest is not enough: checking what a build actually shipped,
 * reproducing a report against the exact artifact, or lifting a release out of
 * the registry without configuring a Composer client for it.
 *
 * Not the Composer dist endpoint: that one is addressed by commit reference
 * and authenticated by a repository token, where this is addressed by version
 * row and authenticated by the panel session. It counts the same, though — an
 * archive that left the registry is a download whoever asked for it, and a
 * counter that quietly omitted the ones an operator took would be a different
 * number than the one it claims to be.
 *
 * Outside the panel's own `/admin` paths so it cannot race Filament's route
 * registration, as the download and SBOM exports are.
 *
 * @see DownloadArchiveAction
 */
class VersionArchiveController extends Controller
{
    public function __construct(private readonly ArchiveStore $archives) {}

    public function __invoke(Request $request, PackageVersion $version): StreamedResponse|RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        // Resolved through the caller's own visibility, so a package they may
        // not see and one that does not exist read alike — the same rule the
        // exports follow. The permission check is the package's `view`,
        // because an archive is the package's published content and nothing
        // that could not open its page has any business pulling it.
        $package = Package::query()
            ->visibleToUser($user)
            ->whereKey($version->package_id)
            ->first();

        abort_unless($package instanceof Package && $user->can('view', $package), 404);

        abort_if(
            $version->archive_path === null,
            404,
            "No archive is stored for {$package->name}@{$version->version}; syncing the package will build it.",
        );

        $disk = $this->archives->disk();

        // A row's path can outlive its file — storage lost on a redeploy, an
        // object deleted out from under us — and handing over a link to
        // nothing is worse than saying so. One metadata call against a
        // transfer orders of magnitude larger, exactly as the dist endpoint
        // spends it.
        abort_unless(
            $disk->exists($version->archive_path),
            404,
            "The stored archive for {$package->name}@{$version->version} is missing from the dist disk.",
        );

        $filename = ArchiveStore::downloadFilename($package->name, $version->version);

        // Only an archive actually being served counts; every 404 above
        // returned before reaching this line.
        //
        // A HEAD is not one of them. Laravel answers HEAD on every GET route
        // and drops the body far below this method, so a link checker or a
        // proxy probing the URL would otherwise land in total_downloads as a
        // download that took no bytes.
        //
        // No token prefix, because none was presented: this request carried a
        // panel session instead, and inventing a value for that column would
        // put something in the download export that no token will ever match.
        if ($request->isMethod('GET')) {
            PackageDownloaded::dispatch($package->id, $version->id, $version->version, null);
        }

        // A disk that can issue its own URLs takes the transfer, rather than
        // pinning a PHP worker for the length of it. The URL is a bearer
        // credential for this one object until it expires minutes later, and
        // it is minted only after the visibility check above has passed — the
        // same bargain the dist endpoint makes, and only ever in that shape.
        $url = $this->archives->temporaryUrl($version->archive_path);

        if ($url !== null) {
            // The archive is immutable; this response is not — it carries a
            // URL that stops working in minutes.
            return redirect()->away($url, headers: ['Cache-Control' => 'no-store']);
        }

        return $disk->download($version->archive_path, $filename, [
            'Content-Type' => 'application/zip',
            // Who may have these bytes depends on the session that asked, so
            // no shared cache is party to the decision, and nothing addressed
            // by row id may be replayed as though it were immutable.
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
