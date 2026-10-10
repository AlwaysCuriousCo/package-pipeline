<?php

use App\Http\Controllers\WordPressController;
use App\Http\Middleware\AuthenticateWordPress;
use App\Http\Middleware\ResolveComposerRepository;
use Illuminate\Support\Facades\Route;

/*
 * The subset of api.wordpress.org a site asks about its plugins and themes,
 * plus the zips themselves. Nothing in WordPress calls these on its own: a
 * mu-plugin on the site points the update and information filters here for
 * the slugs this registry serves. See docs/wordpress.md.
 *
 * Mounted twice at the same pair of prefixes as the other protocol surfaces,
 * resolved by the same middleware, authenticated by the same tokens — read
 * ability, as the Basic password or a bearer. AuthenticateWordPress only
 * changes what a refusal looks like: a 401 with an empty object, never a body
 * an update check would try to merge.
 *
 * The paths keep wordpress.org's own versioned layout under /wp so the filter
 * code reads like the requests core already makes. They are registered
 * without the trailing slash wordpress.org uses; the router accepts either.
 *
 * Registered from bootstrap/app.php beside the other protocol surfaces, and
 * outside the `web` group for the same reason: a site holds no cookie, and
 * the update checks are POSTs with no CSRF token to carry.
 */
$wordpress = function (): void {
    Route::middleware(AuthenticateWordPress::class)->group(function (): void {
        Route::post('/wp/plugins/update-check/1.1', [WordPressController::class, 'pluginUpdateCheck'])
            ->name('plugins.update-check');
        Route::post('/wp/themes/update-check/1.1', [WordPressController::class, 'themeUpdateCheck'])
            ->name('themes.update-check');
        Route::get('/wp/plugins/info/1.2', [WordPressController::class, 'pluginInformation'])
            ->name('plugins.info');
        Route::get('/wp/themes/info/1.2', [WordPressController::class, 'themeInformation'])
            ->name('themes.info');
        Route::get('/wp/dist/{slug}/{version}.zip', [WordPressController::class, 'dist'])
            // Greedy segment: a version carries dots.
            ->where(['slug' => '[^/]+', 'version' => '[^/]+'])
            ->name('dist');
    });
};

Route::middleware(ResolveComposerRepository::class)
    ->name('wordpress.')
    ->group($wordpress);

Route::middleware(ResolveComposerRepository::class)
    ->prefix('r/{repositoryPath}')
    ->where(['repositoryPath' => '[a-z0-9-]+'])
    ->name('wordpress.repository.')
    ->group($wordpress);
