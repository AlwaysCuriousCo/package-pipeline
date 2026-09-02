<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Repository;
use App\Services\PackagePage;
use App\Support\PageSchema;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public page for one package.
 *
 * Anonymous by design and by definition: this is the surface an admin
 * switches on so that a package can be linked to, read about and — where the
 * repository is public — installed from, by somebody who has no account here
 * and never will.
 *
 * Everything it will show is decided on the package row and its repository's
 * public flag, never on the request. A page that is not enabled is a 404
 * rather than a 403: the registry has nothing to say about a package it does
 * not publish a page for, including whether it exists.
 */
class PackagePageController extends Controller
{
    public function __construct(private readonly PackagePage $pages) {}

    public function __invoke(Request $request, string $vendor, string $package): View
    {
        /** @var Repository $repository */
        $repository = $request->attributes->get('composerRepository');

        $package = $this->resolve($repository, $vendor, $package);

        return view('pages.package', [
            'repository' => $repository,
            'package' => $package,
            'body' => $this->pages->render($package),
            'downloads' => $downloads = $package->pageDownloads(),
            'latest' => $downloads->offersAny() ? $package->pageLatestVersion() : null,
            'versions' => $package->page_versions ? $package->pageVersions() : collect(),
            'commands' => $package->pageShowsInstall() ? $package->installCommands() : null,
            'sponsor' => $package->pageSponsorPlan(),
            'schema' => PageSchema::package($package),
        ]);
    }

    /**
     * The package this URL addresses, or a 404.
     *
     * Scoped to the repository the mount resolved, because names are unique
     * per repository rather than per registry — /r/internal/p/acme/widgets
     * and /p/acme/widgets are allowed to be different packages, and must
     * never be able to resolve to each other's.
     *
     * The name is normalized the same way the stored one was, so a link
     * someone typed in mixed case reaches the page rather than a 404.
     */
    private function resolve(Repository $repository, string $vendor, string $package): Package
    {
        // Lowercased for the reason the metadata endpoint lowercases: that is
        // how the column is stored, and folding the input rather than the
        // column keeps the lookup on the index while still resolving a URL
        // somebody typed or a link written in the name's original casing.
        $name = mb_strtolower("{$vendor}/{$package}");

        $found = $repository->packages()
            ->withPage()
            ->where('name', $name)
            ->with('composerRepository')
            ->first();

        abort_unless($found instanceof Package, 404, 'No package page is published at this address.');

        // The package may be served from several repositories, and everything
        // this page prints that is about a *mount* rather than about the
        // package — the URL to configure, the install commands, whether an
        // anonymous visitor may download anything — belongs to the one that
        // was asked for. @see Package::servingRepository()
        return $found->servedFrom($repository);
    }
}
