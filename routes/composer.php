<?php

use App\Http\Controllers\ComposerRepositoryController;
use App\Http\Middleware\AuthenticateComposer;
use App\Http\Middleware\ResolveComposerRepository;
use Illuminate\Support\Facades\Route;

/*
 * The Composer v2 repository API. A consuming project opts in with:
 *   composer config repositories.private composer <this-app's-url>
 *
 * The same endpoints are mounted twice: at the root, serving the default
 * repository, and under /r/{path} for every named repository. The middleware
 * resolves which repository a request addresses; the controller scopes every
 * query to it.
 *
 * Registered from bootstrap/app.php's routing `then` closure rather than from
 * web.php, so that none of the `web` group applies. Composer holds no cookie
 * and sends none: every request through StartSession is therefore a *new*
 * session, which on the shipped `database` driver is an INSERT — one row per
 * metadata fetch, so several hundred per `composer update`, none of which is
 * ever read again and none of which anything prunes. Nothing here reads a
 * session either: authentication is a token (AuthenticateComposer), and the
 * two POSTs below already had to be excluded from CSRF because the clients
 * that make them have no token to carry.
 *
 * @see ResolveComposerRepository
 * @see AuthenticateComposer
 */
$composer = function (): void {
    Route::middleware(AuthenticateComposer::class)->group(function (): void {
        Route::get('/packages.json', [ComposerRepositoryController::class, 'root'])
            ->name('root');
        Route::get('/search.json', [ComposerRepositoryController::class, 'search'])
            ->name('search');
        Route::get('/list.json', [ComposerRepositoryController::class, 'list'])
            ->name('list');
        // What `composer audit` asks. Composer only ever POSTs here; GET is
        // accepted as well because packagist.org's advisory API answers GET
        // and because it makes the endpoint reachable from a browser when an
        // audit needs explaining. Read authentication, like the rest of the
        // group: an advisory names a package, and naming one is exactly what
        // a private repository does not do to an anonymous caller.
        //
        // Throttled, alone among the read endpoints, because it is the only
        // one that is not fan-out: Composer posts here once per audit with the
        // whole installed set, rather than once per package. A ceiling that
        // would break a cold `composer install` of a few hundred dependencies
        // is nowhere near what one audit spends — and on a mirroring
        // repository each of these can cost an outbound request of the app's.
        Route::match(['get', 'post'], '/security-advisories', [ComposerRepositoryController::class, 'securityAdvisories'])
            ->middleware('throttle:advisories')
            ->name('security-advisories');
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
    //
    // The throttle is keyed by the presented credential, not by the address —
    // see the limiter for why that distinction is the whole point here.
    Route::post('/upload/{vendor}/{package}', [ComposerRepositoryController::class, 'upload'])
        ->where('package', '[^/]+')
        ->middleware(['throttle:uploads', AuthenticateComposer::class.':write'])
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
