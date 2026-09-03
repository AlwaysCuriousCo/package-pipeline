<?php

use App\Http\Controllers\NpmRegistryController;
use App\Http\Middleware\AuthenticateComposer;
use App\Http\Middleware\ResolveComposerRepository;
use Illuminate\Support\Facades\Route;

/*
 * The npm registry API. A consuming project opts in with:
 *   npm config set @acme:registry <this-app's-url>/npm/
 *   npm config set //<host>/npm/:_authToken <token>
 *
 * Mounted twice at the same pair of prefixes as the Composer API, resolved by
 * the same middleware, authenticated by the same tokens — npm sends the token
 * as a bearer credential, which AuthenticateComposer already reads. Under a
 * /npm segment because npm resolves every path relative to its configured
 * registry URL, so unlike Composer's dictated /packages.json the whole
 * surface can live under one prefix and stay clear of the pages at /p.
 *
 * Registered from bootstrap/app.php beside the Composer routes, and outside
 * the `web` group for the same reason: a cookieless client must not be given
 * a session per request, and the PUT has no CSRF token to carry.
 *
 * {name} spans the slash of a scoped @scope/name — the router matches the
 * decoded path, so it also catches a client sending %2f — and only ever a
 * slash after an @scope, which is what keeps npm names and Composer names
 * from ever resolving each other's packages. The tarball route is registered
 * first so its /-/ segment is never swallowed by the packument's {name}.
 */
$npm = function (): void {
    Route::middleware(AuthenticateComposer::class)->group(function (): void {
        Route::get('/npm/{name}/-/{filename}', [NpmRegistryController::class, 'tarball'])
            ->where('name', '(?:@[^/]+/)?[^/]+')
            ->where('filename', '[^/]+\.tgz')
            ->name('tarball');
        Route::get('/npm/{name}', [NpmRegistryController::class, 'packument'])
            ->where('name', '(?:@[^/]+/)?[^/]+')
            ->name('packument');
    });

    // `npm publish`. On the uploads throttle beside the Composer upload,
    // keyed by the presented credential for the same reason.
    Route::put('/npm/{name}', [NpmRegistryController::class, 'publish'])
        ->where('name', '(?:@[^/]+/)?[^/]+')
        ->middleware(['throttle:uploads', AuthenticateComposer::class.':write'])
        ->name('publish');
};

Route::middleware(ResolveComposerRepository::class)
    ->name('npm.')
    ->group($npm);

Route::middleware(ResolveComposerRepository::class)
    ->prefix('r/{repositoryPath}')
    ->where(['repositoryPath' => '[a-z0-9-]+'])
    ->name('npm.repository.')
    ->group($npm);
