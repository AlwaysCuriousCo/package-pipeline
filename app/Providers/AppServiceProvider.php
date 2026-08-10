<?php

namespace App\Providers;

use App\Http\Middleware\AuthenticateComposer;
use App\Notifications\Channels\WebhookChannel;
use App\Support\ActingCredential;
use App\Support\EgressPolicy;
use App\Support\EgressProfile;
use App\Support\HostResolver;
use App\Support\SystemHostResolver;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bound rather than instantiated where it is used, because what a host
        // resolves to is the one input the egress guard cannot be tested
        // against for real — see App\Support\EgressPolicy.
        $this->app->singleton(HostResolver::class, SystemHostResolver::class);

        // A bare EgressPolicy means the mirror's, because the mirror is where
        // one is injected. The webhook profile is asked for by name at the two
        // places that judge an endpoint, since a policy without a profile has
        // not said which set of escape hatches it honours.
        $this->app->bind(EgressPolicy::class, fn (): EgressPolicy => EgressPolicy::for(EgressProfile::Mirror));

        // One per request, so that the credential a token-authenticated write
        // is attributed to cannot outlive the request that presented it.
        $this->app->scoped(ActingCredential::class);
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

        // Registers `webhook` next to `database` and `slack`, so a notification
        // can name it in via() and AdminNotifier can route to one.
        Notification::resolved(
            fn (ChannelManager $channels) => $channels->extend('webhook', fn (): WebhookChannel => new WebhookChannel),
        );

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

        // The management API, keyed by the credential because a provisioning
        // run and a CI fleet both arrive from one egress address and one
        // token's loop must not spend another's budget — and by the address as
        // well, because keying on the credential alone bounds nothing an
        // attacker chooses. A different random bearer on every request is a
        // fresh bucket on every request, so the 401s behind this would be free
        // for ever; the address ceiling is the only one a guesser cannot walk
        // out of, and it is what makes the claim that this bounds guessing true.
        //
        // The per-credential budget is the same as uploads, because it is the
        // same shape of traffic: a machine credential doing bounded work, not a
        // fan-out. A page of a hundred packages per request puts a whole
        // registry within a minute's walk, and a provisioning run past sixty
        // creations a minute is told to wait rather than told no — the 429
        // carries Retry-After.
        RateLimiter::for('api', fn (Request $request): array => $this->credentialAndAddressLimits(
            $request,
            (string) ($request->bearerToken() ?: $request->getPassword()),
        ));

        // `composer audit` posts the whole installed set in one request, which
        // makes this the one Composer read endpoint a limit can apply to
        // without breaking the traffic Composer generates — every other one is
        // asked per package, and a ceiling tight enough to matter there would
        // cut off a cold install of a few hundred dependencies. An audit runs
        // once per `composer update`, so sixty a minute per credential is far
        // past any build; what it bounds is a caller looping the one endpoint
        // here that can name a thousand packages and, on a mirroring
        // repository, spend an outbound request of ours.
        RateLimiter::for('advisories', fn (Request $request): array => $this->credentialAndAddressLimits(
            $request,
            (string) ($request->getPassword() ?: $request->bearerToken()),
        ));

        // Uploads are exactly the traffic that arrives from one egress IP: a CI
        // fleet, and a monorepo release publishing a package per component in a
        // single run — hence the same pair, per credential and per address. The
        // credential is read straight off the request: no token lookup, and its
        // sha256 is what the database stores anyway.
        //
        // Guessing is not what the address budget is for here. This endpoint
        // authenticates as Composer, so a credential that does not resolve is
        // already counted and cut off by AuthenticateComposer's failure ledger,
        // thirty to a minute. What is left for this to bound is the traffic
        // that does authenticate.
        RateLimiter::for('uploads', fn (Request $request): array => $this->credentialAndAddressLimits(
            $request,
            (string) ($request->getPassword() ?: $request->bearerToken()),
        ));

        // A scrape is a dozen aggregate queries, and METRICS_TOKEN is optional
        // — so on an installation that has enabled metrics without one, this is
        // the only thing between an anonymous flood and that dozen per request.
        // The exposition cache usually absorbs it, but it is exactly the kind
        // of protection that must not depend on a tuning knob: a debugging
        // METRICS_CACHE_SECONDS=0 left in place would otherwise remove it.
        //
        // Sixty a minute per address is fifteen Prometheus replicas scraping
        // one instance at the conventional interval, which no deployment does.
        // Per address rather than per credential because the token is optional
        // and there is nothing else to key on.
        RateLimiter::for('metrics', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }

    /**
     * The two ceilings every credentialled endpoint is behind: what one
     * credential may spend, and what one address may spend however many
     * credentials it presents.
     *
     * Both are returned, always, because either alone has a hole. The address
     * budget on its own would throttle an office or a CI fleet for behaving
     * normally, which is why it is several times the credential's. The
     * credential budget on its own is chosen by the caller, and a caller who
     * has not guessed a real token yet can have as many of them as it likes.
     *
     * A request carrying no credential is counted against the address under a
     * key of its own rather than the credential's, so that the two limits never
     * collide on one counter and charge such a request twice.
     *
     * @return list<Limit>
     */
    private function credentialAndAddressLimits(Request $request, string $credential): array
    {
        return [
            Limit::perMinute(300)->by('address:'.$request->ip()),
            Limit::perMinute(60)->by($credential === ''
                ? 'anonymous:'.$request->ip()
                : 'token:'.hash('sha256', $credential)),
        ];
    }
}
