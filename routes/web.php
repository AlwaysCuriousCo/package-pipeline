<?php

use App\Http\Controllers\ComposerRepositoryController;
use App\Http\Controllers\GitHubWebhookController;
use App\Http\Controllers\SourceConnectionController;
use App\Http\Middleware\AuthenticateComposer;
use App\Http\Middleware\ResolveComposerRepository;
use Illuminate\Support\Facades\Route;

// The app is administered entirely through Filament, so the root URL lands on
// the panel's login page. Keep this in sync with the admin panel's path()
// in App\Providers\Filament\AdminPanelProvider.
Route::redirect('/', '/admin/login')->name('home');

// The Composer v2 repository API. A consuming project opts in with:
//   composer config repositories.private composer <this-app's-url>
//
// The same endpoints are mounted twice: at the root, serving the default
// repository, and under /r/{path} for every named repository. The middleware
// resolves which repository a request addresses; the controller scopes every
// query to it.
$composer = function (): void {
    Route::middleware(AuthenticateComposer::class)->group(function (): void {
        Route::get('/packages.json', [ComposerRepositoryController::class, 'root'])
            ->name('root');
        Route::get('/search.json', [ComposerRepositoryController::class, 'search'])
            ->name('search');
        Route::get('/list.json', [ComposerRepositoryController::class, 'list'])
            ->name('list');
        Route::get('/p2/{vendor}/{package}.json', [ComposerRepositoryController::class, 'metadata'])
            // Greedy segment so package names containing dots still match.
            ->where('package', '[^/]+')
            ->name('metadata');
        Route::get('/dist/{vendor}/{package}/{reference}.zip', [ComposerRepositoryController::class, 'dist'])
            ->name('dist');
    });

    // CI publishing a built artifact: multipart `file` (zip) and optional
    // `version`. Under its own /upload segment rather than Packistry's bare
    // POST /{vendor}/{package}, which would collide with the /incoming
    // webhook paths and force a wildcard CSRF exemption.
    Route::post('/upload/{vendor}/{package}', [ComposerRepositoryController::class, 'upload'])
        ->where('package', '[^/]+')
        ->middleware(AuthenticateComposer::class.':write')
        ->name('upload');
};

Route::middleware(ResolveComposerRepository::class)
    ->name('composer.')
    ->group($composer);

Route::middleware(ResolveComposerRepository::class)
    ->prefix('r/{repositoryPath}')
    ->where(['repositoryPath' => '[a-z0-9-]+'])
    ->name('composer.repository.')
    ->group($composer);

// Incoming provider deliveries, which sync a package as soon as a tag or
// branch moves. Both are unauthenticated by design and verify GitHub's
// signature instead; see docs/webhooks.md.
//
// The first is the GitHub App's own webhook, configured once on the app and
// carrying events for every repository in every installation. The second is
// the per-repository fallback, for packages whose source is not an installed
// app — its signature is checked against that package's own secret.
Route::post('/incoming/github', [GitHubWebhookController::class, 'app'])
    ->name('webhooks.github');
Route::post('/incoming/github/{package}', [GitHubWebhookController::class, 'repository'])
    ->name('webhooks.github.package');

// The GitHub App install handshake for connecting a source. Both legs are
// admin-only: the callback attaches an installation to a source, so it must
// never be reachable by an anonymous request. Register the app's "Setup URL"
// as <this-app's-url>/sources/github/callback.
Route::middleware('auth')->group(function () {
    Route::get('/sources/github/callback', [SourceConnectionController::class, 'callback'])
        ->name('sources.github.callback');
    // Connecting an account that has no source yet; the source is created from
    // the installation GitHub hands back.
    Route::get('/sources/connect', [SourceConnectionController::class, 'connectNew'])
        ->name('sources.connect.new');
    Route::get('/sources/{source}/connect', [SourceConnectionController::class, 'connect'])
        ->name('sources.connect');
});
