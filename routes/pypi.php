<?php

use App\Http\Controllers\PypiRegistryController;
use App\Http\Middleware\AuthenticateComposer;
use App\Http\Middleware\ResolveComposerRepository;
use Illuminate\Support\Facades\Route;

/*
 * The Python package index. A consuming project opts in with:
 *   pip config set global.index-url <this-app's-url>/pypi/simple/
 * (or extra-index-url, to keep pypi.org beside it), authenticating with the
 * token as the basic password — `__token__` or any other username — and
 * publishes with twine pointed at <this-app's-url>/pypi/legacy/.
 *
 * Mounted twice at the same pair of prefixes as the Composer and npm APIs,
 * resolved and authenticated by the same middleware. The paths follow
 * pypi.org's own layout (/simple/, /legacy/) so the two lines above read
 * exactly like the ones every Python developer has already configured.
 *
 * Registered from bootstrap/app.php beside the other protocol surfaces, and
 * outside the `web` group for the same reason: pip and twine hold no cookie
 * and carry no CSRF token.
 */
$pypi = function (): void {
    Route::middleware(AuthenticateComposer::class)->group(function (): void {
        Route::get('/pypi/simple', [PypiRegistryController::class, 'index'])
            ->name('index');
        Route::get('/pypi/simple/{name}', [PypiRegistryController::class, 'project'])
            ->where('name', '[^/]+')
            ->name('project');
        Route::get('/pypi/files/{name}/{versionString}/{filename}', [PypiRegistryController::class, 'file'])
            ->where(['name' => '[^/]+', 'versionString' => '[^/]+', 'filename' => '[^/]+'])
            ->name('file');
    });

    // `twine upload`. On the uploads throttle beside the other two publish
    // endpoints, keyed by the presented credential for the same reason.
    Route::post('/pypi/legacy', [PypiRegistryController::class, 'upload'])
        ->middleware(['throttle:uploads', AuthenticateComposer::class.':write'])
        ->name('upload');
};

Route::middleware(ResolveComposerRepository::class)
    ->name('pypi.')
    ->group($pypi);

Route::middleware(ResolveComposerRepository::class)
    ->prefix('r/{repositoryPath}')
    ->where(['repositoryPath' => '[a-z0-9-]+'])
    ->name('pypi.repository.')
    ->group($pypi);
