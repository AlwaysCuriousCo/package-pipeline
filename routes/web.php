<?php

use App\Http\Controllers\DownloadExportController;
use App\Http\Controllers\GitHubWebhookController;
use App\Http\Controllers\GitLabWebhookController;
use App\Http\Controllers\PasswordSetupController;
use App\Http\Controllers\SbomExportController;
use App\Http\Controllers\SourceConnectionController;
use App\Http\Controllers\SsoController;
use App\Http\Controllers\VersionArchiveController;
use Illuminate\Support\Facades\Route;

// The app is administered entirely through Filament, so the root URL lands on
// the panel's login page. Keep this in sync with the admin panel's path()
// in App\Providers\Filament\AdminPanelProvider.
Route::redirect('/', '/admin/login')->name('home');

// The landing point for the password link `admin:create`, `user:add` and
// `user:reset-password` print. One opaque segment and no query string, so the
// URL a new admin is asked to open carries neither their address nor a token
// — see App\Auth\PasswordSetupLink for why that matters. Outside the panel's
// own /admin paths so it cannot race Filament's route registration.
Route::get('/password-setup/{payload}', PasswordSetupController::class)
    ->where('payload', '[A-Za-z0-9\-_]+')
    ->name('password-setup');

// The Composer v2 repository API is registered in routes/composer.php, outside
// this file and outside the `web` group with it — a cookieless client must not
// be given a session per request. See that file for the whole of why.

// Incoming provider deliveries, which sync a package as soon as a tag or
// branch moves. Both are unauthenticated by design and verify GitHub's
// signature instead; see docs/webhooks.md.
//
// Throttled because that signature is the whole of the authentication, and
// asking for one costs an HMAC over a body of the sender's choosing plus the
// lookup behind it — none of which requires holding the secret.
Route::middleware('throttle:webhooks')->group(function (): void {
    // The first is the GitHub App's own webhook, configured once on the app and
    // carrying events for every repository in every installation. The second is
    // the per-repository fallback, for packages whose source is not an installed
    // app — its signature is checked against that package's own secret.
    Route::post('/incoming/github', [GitHubWebhookController::class, 'app'])
        ->name('webhooks.github');
    Route::post('/incoming/github/{package}', [GitHubWebhookController::class, 'repository'])
        ->name('webhooks.github.package');

    // GitLab's per-repository leg. GitLab has no account-wide webhook to mirror
    // the GitHub App's, and it authenticates by replaying the hook's secret in a
    // header rather than signing the body.
    Route::post('/incoming/gitlab/{package}', [GitLabWebhookController::class, 'repository'])
        ->name('webhooks.gitlab.package');
});

// The SSO round trip for runtime-configured login providers. The login page
// renders one button per active authentication source; the callback signs
// the resolved user into the Filament panel.
//
// Throttled because the callback dials the identity provider: without a
// ceiling, anyone who can reach /auth/* can make this app generate outbound
// requests at whatever rate they like.
Route::middleware('throttle:sso')->group(function (): void {
    Route::get('/auth/{source}/redirect', [SsoController::class, 'redirect'])
        ->name('sso.redirect');
    Route::get('/auth/{source}/callback', [SsoController::class, 'callback'])
        ->name('sso.callback');
});

// Download statistics as a CSV, streamed. A plain route rather than something
// the panel action returns, because Livewire delivers a file by base64-encoding
// it into its response — which would hold the whole of the fastest-growing
// table in the schema in memory. Outside /admin so it cannot race Filament's
// route registration; scoped to the signed-in admin's own grants.
Route::get('/exports/downloads', DownloadExportController::class)
    ->middleware('auth')
    ->name('exports.downloads');

// A CycloneDX bill of materials, streamed for the same reason: a component per
// package version is a document nobody should be assembling in memory. Scoped
// to the signed-in admin's own grants; `?package=` narrows it to one package.
Route::get('/exports/sbom', SbomExportController::class)
    ->middleware('auth')
    ->name('exports.sbom');

// The stored zip for one package version, for an admin who needs the artifact
// itself rather than what the panel says about it. Scoped to the signed-in
// admin's own grants, and outside /admin for the same reason the exports are.
Route::get('/downloads/versions/{version}', VersionArchiveController::class)
    ->middleware('auth')
    ->name('downloads.version');

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
