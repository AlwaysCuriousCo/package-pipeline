<?php

namespace App\Providers;

use App\Filament\Livewire\EmailNotificationsForm;
use App\Http\Middleware\AuthenticateComposer;
use App\Notifications\Channels\WebhookChannel;
use App\Support\ActingCredential;
use App\Support\EgressPolicy;
use App\Support\EgressProfile;
use App\Support\HostResolver;
use App\Support\SystemHostResolver;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Component;
use Livewire\Livewire;

use function Livewire\on;
use function Livewire\store;

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

        $this->carryToastsInTheMessageRatherThanTheSession();
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

        // Registers `webhook` next to `database` and `slack`, so a notification
        // can name it in via() and AdminNotifier can route to one.
        Notification::resolved(
            fn (ChannelManager $channels) => $channels->extend('webhook', fn (): WebhookChannel => new WebhookChannel),
        );

        // The profile page's email-notifications section. Registered here, in a
        // provider that runs on every request, rather than alongside the panel
        // that renders it: a Livewire update posts to /livewire/update, which
        // is outside the panel's middleware and so never boots it. A component
        // known only to the panel resolves when the page is drawn and then
        // cannot be found when its form is submitted — which surfaces as
        // Livewire's release-token mismatch rather than as anything naming the
        // component. The plugin registers its own for the same reason.
        //
        // Unconditional, unlike the panel-side registration: the alias costs
        // nothing when the section is not rendered, and making it depend on
        // config would only add a second way for the two halves to disagree.
        Livewire::component('email_notifications_form', EmailNotificationsForm::class);

        $this->defineRateLimiters();

        AboutCommand::add('Registry', fn (): array => [
            'Scheduler locking' => $this->schedulerLocking(),
        ]);
    }

    /**
     * Hand a toast to the browser in the response that raised it.
     *
     * Filament's own delivery is a relay through the session: `send()` writes
     * the notification to it, a dehydrate hook moves it to
     * `filament.claimed_notifications` and dispatches `notificationsSent` with
     * no payload, and the notifications component then makes a *second*
     * request whose only job is to read that key back out.
     *
     * Which asks the session to carry a value between two requests the browser
     * fires back to back — and Laravel saves the session in `terminate()`,
     * after the response has already gone out. Locally the write always wins
     * that race. Behind a load balancer it does not: the second request reads
     * the session before the first has written it, finds nothing, and then
     * saves its own stale copy over the top — so the toast is not merely late,
     * it is gone, and no later page load shows it either. An action that
     * redirects cannot be helped here at all — the next page load is the
     * only place its toast can appear, so it has no response to ride in;
     * anything whose toast must not be lost stays on the page (#27).
     *
     * So we read the notification before Filament's hook does and dispatch it
     * *with* its payload, to the `notificationSent` listener the component
     * already carries for client-side notifications. There is still a second
     * request — Filament renders a notification server side, so there has to
     * be — but it now arrives holding the toast instead of going looking for
     * it, and nothing has to survive in between. Filament's relay is left
     * running alongside rather than replaced; the two cannot double up,
     * for the reason given below.
     *
     * Registered from `register()` rather than `boot()`, and that is
     * load-bearing: dehydrate hooks fire in registration order, and a listener
     * added after Filament's dispatches too late to be serialised into the
     * response — the event is simply dropped. Registering before it puts this
     * in the slot that works.
     *
     * Nothing here is specific to tokens: it is every toast raised by an
     * action that stays on the page.
     */
    private function carryToastsInTheMessageRatherThanTheSession(): void
    {
        on('dehydrate', function (Component $component): void {
            if (! Livewire::isLivewireRequest()) {
                return;
            }

            // A redirect is the one case where the session relay is sound —
            // the next page load pulls it — and dispatching into a response
            // the browser is about to navigate away from would lose it.
            if (store($component)->has('redirect')) {
                return;
            }

            // Read, not taken. Filament's relay still runs and still clears
            // these keys on its own schedule, so a deployment where it works
            // keeps working and `assertNotified()` still has something to
            // assert on. A toast arriving down both routes is not shown twice:
            // the component keys them by id, so the second put replaces the
            // first.
            //
            // Both keys, so the order this lands in stops mattering: whichever
            // hook ran first, what it left behind is picked up here.
            $notifications = array_merge(
                session()->get('filament.notifications') ?? [],
                session()->get('filament.claimed_notifications') ?? [],
            );

            foreach ($notifications as $notification) {
                $component->dispatch('notificationSent', notification: $notification);
            }
        });
    }

    /**
     * Whether this installation's cache store can hold the locks correctness
     * depends on, said where an operator can ask.
     *
     * The cache here is not only a cache. Every scheduled task runs
     * `onOneServer()`, every one of them `withoutOverlapping()`, and each
     * package's sync carries a `UniqueLock` — all three are entries in the
     * cache store, and all three quietly become no-ops when that store is not
     * shared between the processes competing for them. The failure looks exactly
     * like everything working: two rebuilds of one package run at once, and the
     * loser's prune deletes the winner's rows.
     *
     * `array` is not a milder version of `file`, it is the worse one. A file
     * lock is at least shared by every process on one container, which is enough
     * for a single-container deployment; `array` keeps the lock in the memory of
     * one process, and `schedule:run` is a fresh process every minute. So it
     * defeats the scheduler's locks on an installation that has only ever run
     * one container — the one deployment shape nobody thinks to suspect.
     *
     * `about` rather than a boot-time warning because the alternative is a log
     * line every minute for as long as the misconfiguration lasts, and a warning
     * that noisy is one an operator filters out. This is the framework's own
     * place to ask what a deployment is actually configured as, and the
     * deployment docs point at it.
     */
    private function schedulerLocking(): string
    {
        $store = (string) config('cache.default');

        return match ((string) config("cache.stores.{$store}.driver")) {
            'array', 'null' => "<fg=red;options=bold>DEFEATED</> — `{$store}` holds locks in one process's memory, "
                .'so the scheduler takes a fresh set every minute and holds nothing',
            'file' => "<fg=yellow;options=bold>ONE CONTAINER ONLY</> — `{$store}` holds locks on a local disk, "
                .'so a second container shares none of them',
            default => "shared, via the `{$store}` store",
        };
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
        // Self-serve signup: the cheapest endpoint on the site to abuse
        // and the least legitimate reason to hit it twice in a minute.
        RateLimiter::for('registration', function (Request $request): array {
            return [Limit::perMinute(5)->by('address:'.$request->ip())];
        });

        // The payment merchant's deliveries. Rarer than the git providers'
        // by orders of magnitude, and each unverified request still costs a
        // signature check — so a tight per-address limit loses nothing.
        RateLimiter::for('merchant-webhooks', function (Request $request): array {
            return [Limit::perMinute(120)->by('address:'.$request->ip())];
        });

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

        // The public pages, which are the only surface here that anonymous
        // traffic is *invited* to — a link in a chat, a search result, a
        // social card being generated. Generous enough that a person clicking
        // through a repository's package list never meets it, and low enough
        // that a scraper walking every page and every download link is
        // bounded. Keyed by address alone, because there is no credential on
        // this surface to key by.
        RateLimiter::for('pages', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
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
