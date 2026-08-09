<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateComposer;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Laravel's policy discovery finds App\Policies\PackagePolicy and
        // friends on its own, but not the policy for Shield's own Role model,
        // which lives in a vendor namespace. This registers it.
        FilamentShield::enforcePolicies();

        // There is no front-end build: every page the app serves is Filament's,
        // and Filament's assets are published by `filament:upgrade`. Without
        // this, `artisan dev` would launch a Vite dev server for nothing and
        // then take the whole process group down with it when it failed.
        DevCommands::except('vite');

        $this->defineRateLimiters();
    }

    /**
     * Ceilings for the endpoints a stranger can reach, applied in routes/web.php.
     *
     * Nothing here throttles the Composer read endpoints. One `composer install`
     * fans out a metadata request per package and a dist request per install,
     * and an office or a CI fleet reaches the registry from a single egress
     * address — a per-address ceiling there would break ordinary use long before
     * it inconvenienced anybody. Failed Composer authentication is bounded
     * instead, in AuthenticateComposer, where only the failures are counted.
     *
     * @see AuthenticateComposer
     */
    private function defineRateLimiters(): void
    {
        // A delivery is authenticated by its signature, and computing that
        // signature is work a stranger holding no secret can ask for. Two
        // limits, because either alone has a hole: the per-package budget stops
        // one repository (busy or hostile) spending the allowance the rest of
        // the registry's deliveries need, and the per-address budget is what
        // stops an attacker buying more of them by naming a different package
        // on every request. The GitHub App's own hook names no package in its
        // path — it delivers for every installed repository at once — so the
        // address budget is the only one that applies to it.
        RateLimiter::for('webhooks', function (Request $request): array {
            $limits = [Limit::perMinute(300)->by('address:'.$request->ip())];

            $package = $request->route('package');

            if (is_string($package)) {
                $limits[] = Limit::perMinute(60)->by('package:'.$package);
            }

            return $limits;
        });

        // The callback leg dials the identity provider, so an unauthenticated
        // request here spends an outbound round trip of the app's. A sign-in is
        // two requests, making this thirty sign-ins a minute from one address:
        // past what an office behind one NAT does on a Monday morning, well
        // short of a useful amplifier.
        RateLimiter::for('sso', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        // Keyed by the credential rather than the address, because uploads are
        // exactly the traffic that arrives from one egress IP: a CI fleet, and a
        // monorepo release publishing a package per component in a single run.
        // The credential is read straight off the request — no token lookup, and
        // its sha256 is what the database stores anyway. A request carrying none
        // is answered 401 by the auth middleware regardless of this.
        RateLimiter::for('uploads', function (Request $request): Limit {
            $credential = (string) ($request->getPassword() ?: $request->bearerToken());

            return Limit::perMinute(60)->by(
                $credential === '' ? 'address:'.$request->ip() : 'token:'.hash('sha256', $credential),
            );
        });
    }
}
