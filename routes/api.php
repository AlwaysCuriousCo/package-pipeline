<?php

use App\Enums\TokenAbility;
use App\Http\Controllers\Api\V1\PackageController;
use App\Http\Controllers\Api\V1\RepositoryController;
use App\Http\Middleware\AuthenticateApi;
use Illuminate\Support\Facades\Route;

/**
 * The management API, for the callers that cannot open a browser: CI creating
 * a package and triggering its sync, a provisioning script standing a registry
 * up from a manifest.
 *
 * Everything Composer itself speaks lives in routes/web.php and is untouched by
 * this — the two surfaces share a credential store and nothing else. See
 * docs/api.md.
 */

// Versioned in the path rather than in a header, so that "which API is this"
// is answerable from a CI log, a proxy rule and a curl line. The version is a
// promise about response *shape*: fields are added inside v1, never removed or
// re-meaninged.
Route::prefix('v1')
    ->name('api.v1.')
    // In front of authentication, so an unauthenticated flood is bounded by
    // the same ceilings as an authenticated one — and by the per-address one
    // whatever credential each request carries, which is what puts a limit on
    // guessing at tokens here, the job AuthenticateComposer's own failure
    // counter does for the Composer endpoints.
    ->middleware('throttle:api')
    ->group(function (): void {
        $read = AuthenticateApi::class.':'.TokenAbility::ApiRead->value;
        $write = AuthenticateApi::class.':'.TokenAbility::ApiWrite->value;
        $delete = AuthenticateApi::class.':'.TokenAbility::ApiDelete->value;

        Route::middleware($read)->group(function (): void {
            Route::get('/repositories', [RepositoryController::class, 'index'])
                ->name('repositories.index');
            Route::get('/repositories/{repository}', [RepositoryController::class, 'show'])
                ->whereNumber('repository')
                ->name('repositories.show');

            Route::get('/packages', [PackageController::class, 'index'])
                ->name('packages.index');
            Route::get('/packages/{package}', [PackageController::class, 'show'])
                ->whereNumber('package')
                ->name('packages.show');
        });

        Route::middleware($write)->group(function (): void {
            Route::post('/packages', [PackageController::class, 'store'])
                ->name('packages.store');
            Route::post('/packages/{package}/sync', [PackageController::class, 'sync'])
                ->whereNumber('package')
                ->name('packages.sync');
        });

        // Its own ability, not `api:write`. Creating a package and syncing one
        // are additive and repeatable; deleting unpublishes something a
        // consuming project may already have pinned in a lock file, and the
        // credential that runs a release pipeline has no reason to hold it.
        Route::delete('/packages/{package}', [PackageController::class, 'destroy'])
            ->whereNumber('package')
            ->middleware($delete)
            ->name('packages.destroy');
    });
