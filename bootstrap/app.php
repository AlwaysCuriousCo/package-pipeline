<?php

use App\Http\Controllers\MetricsController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Mounted at /api, and versioned under that by the route file itself.
        // Everything Composer speaks stays in web.php at the paths the protocol
        // dictates; this is the management surface, which is ours to shape.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The Prometheus scrape endpoint, registered here rather than in
        // web.php so that it inherits none of the `web` group. A scraper holds
        // no cookie and needs no CSRF token, and putting it behind the session
        // middleware would start — and on the default `database` driver,
        // *store* — a session for every scrape, which is one row every fifteen
        // seconds for as long as the registry runs.
        //
        // It answers 404 until METRICS_ENABLED is set; see MetricsController
        // for why an internet-facing registry does not ship this switched on.
        then: fn () => Route::get('/metrics', MetricsController::class)->name('metrics'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // TLS ends at the platform's load balancer, so without this the app
        // sees every request as http and signed URLs (the password reset
        // link admin:create prints) fail their signature check. Traffic only
        // reaches the app through that balancer, so trusting all proxies is
        // safe here.
        $middleware->trustProxies(at: '*');

        // The admin-only routes outside the Filament panel (the GitHub App
        // connect handshake) use the plain "auth" middleware, which has no
        // login route of its own to fall back on. Keep this in sync with the
        // panel's path() in App\Providers\Filament\AdminPanelProvider.
        $middleware->redirectGuestsTo('/admin/login');

        // GitHub posts deliveries with no session and no token; they carry a
        // signature instead, which the webhook controller checks before doing
        // anything with the payload. Artifact uploads are likewise stateless:
        // CI authenticates with an access token, not a session. So is the
        // advisory endpoint, which Composer reaches by POST because that is
        // how it sends a package list — it is a read, and it holds no session
        // to carry a token in.
        $middleware->validateCsrfTokens(except: [
            'incoming/*',
            'upload/*',
            'r/*/upload/*',
            'security-advisories',
            'r/*/security-advisories',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The upload endpoint is driven by curl in CI scripts, which rarely
        // send an Accept header; a validation failure must still answer 422
        // JSON, never a redirect to a login page. The webhook endpoints answer
        // JSON from the controller either way, so a framework-generated 404 or
        // 429 landing in a provider's delivery log should read the same.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || $request->is('upload/*')
                || $request->is('r/*/upload/*')
                || $request->is('incoming/*')
                // Composer sends no Accept header when it posts a package
                // list, and a validation failure answered with a redirect
                // would surface as an unreadable audit rather than an error.
                || $request->is('security-advisories')
                || $request->is('r/*/security-advisories')
                || $request->expectsJson(),
        );
    })->create();
