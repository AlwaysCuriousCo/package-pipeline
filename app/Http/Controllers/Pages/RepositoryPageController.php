<?php

namespace App\Http\Controllers\Pages;

use App\Http\Controllers\Controller;
use App\Models\Repository;
use App\Services\PackagePage;
use App\Support\PageSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A repository's own landing page, served at the URL its Composer endpoints
 * hang off — "/" for the default repository, "/r/{path}" for a named one.
 *
 * Deliberately the same URL, because it is the one an operator is already
 * handing out: a project is told to run `composer config repositories.x
 * composer https://packages.example.com`, and the first thing a person does
 * with that URL is open it. Before this it answered a redirect to a login
 * form for an account they do not have.
 *
 * Which is still what it answers when no page is enabled — the root has
 * always landed on the panel, and a registry that has not opted into
 * publishing anything keeps that behaviour exactly.
 */
class RepositoryPageController extends Controller
{
    public function __construct(private readonly PackagePage $pages) {}

    public function __invoke(Request $request): View|RedirectResponse
    {
        /** @var Repository $repository */
        $repository = $request->attributes->get('composerRepository');

        if (! $repository->hasPage()) {
            // The default repository's mount is the site root, whose long-
            // standing answer is the panel's login page. A named repository's
            // mount has never answered anything, and a 404 is what it should
            // go on saying rather than sending a stranger to a login form.
            abort_unless($repository->isDefault(), 404, 'No page is published for this repository.');

            return redirect('/admin/login');
        }

        $packages = $repository->pagePackages();

        return view('pages.repository', [
            'repository' => $repository,
            'body' => $this->pages->renderRepository($repository),
            'packages' => $packages,
            'schema' => PageSchema::repository($repository, $packages),
        ]);
    }
}
