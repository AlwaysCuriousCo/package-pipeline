<?php

namespace App\Http\Controllers;

use App\Enums\GitHubAppOwner;
use App\Filament\Pages\GetStarted;
use App\Filament\Resources\GitHubApps\GitHubAppResource;
use App\Filament\Resources\GitHubApps\Pages\ManageGitHubApp;
use App\Models\GitHubAppRegistration;
use App\Services\GitHub\GitHubApp;
use App\Services\GitHub\GitHubAppManifest;
use App\Services\GitHub\GitHubError;
use App\Support\HttpTimeouts;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Registers this registry's GitHub App from a manifest, so nobody has to copy
 * an App ID, a .pem and a webhook secret out of GitHub and into secrets.
 *
 * The admin is sent to GitHub with a manifest describing the app; GitHub
 * creates it under their account or organisation and returns them here with
 * a one-time code, which this exchanges for the app's credentials. Only users
 * and organisations accept manifests — an enterprise's app is registered on
 * the panel page instead, from a prefilled form.
 *
 * @see GitHubAppManifest
 */
class GitHubAppRegistrationController extends Controller
{
    /**
     * The session key holding the pending registration. Like the install
     * handshake's, its `state` ties GitHub's redirect back to the browser
     * that started it, so a code from anybody else's flow cannot register an
     * app here.
     */
    public const SESSION_KEY = 'github-app.pending';

    /**
     * The name of an app registered a moment ago, for the install handshake
     * that immediately follows to pick up and report when it completes.
     */
    public const REGISTERED_KEY = 'github-app.registered';

    /**
     * A page that posts the manifest to GitHub. GitHub only takes a manifest
     * as a form POST from the browser, so this is a form that submits itself,
     * with a button for when scripts are off.
     */
    public function manifest(Request $request): View|RedirectResponse
    {
        abort_unless($request->user()?->can('create', GitHubAppRegistration::class), 403);

        $pending = $request->session()->get(self::SESSION_KEY);

        if (! is_array($pending)) {
            return $this->fail(
                'Nothing to register',
                'There is no registration in progress in this browser.',
                'Start again with Register GitHub App.',
            );
        }

        $owner = GitHubAppOwner::from($pending['owner_type']);

        return view('github-app.manifest', [
            'action' => $owner->newAppUrl($pending['owner'])
                .'?'.http_build_query(['state' => $pending['state']]),
            'manifest' => json_encode((new GitHubAppManifest($pending['name'], (bool) ($pending['public'] ?? false)))->toArray(), JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * Where GitHub returns the admin once the app exists, with a code that is
     * good for an hour and for one exchange.
     */
    public function registered(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('create', GitHubAppRegistration::class), 403);

        $pending = $request->session()->pull(self::SESSION_KEY);
        $state = $request->query('state');

        if (! is_array($pending) || ! is_string($state) || ! hash_equals($pending['state'], $state)) {
            return $this->fail(
                'Registration could not be verified',
                'GitHub returned to a registration this browser did not start, so its credentials were not collected.',
                'If GitHub created an app on the way here, delete it on GitHub, then register again from this page.',
            );
        }

        $owner = GitHubAppOwner::from($pending['owner_type']);
        $apps = $owner->appsUrl($pending['owner']);
        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return $this->fail(
                'Nothing was registered',
                'GitHub returned without creating an app.',
                'Register again from this page.',
            );
        }

        try {
            $conversion = Http::baseUrl(config('services.github.app.api_url'))
                ->timeout(HttpTimeouts::API)
                ->connectTimeout(HttpTimeouts::CONNECT)
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->acceptJson()
                // Sent with no body at all. post() would send `[]`, which
                // this endpoint validates and refuses with a 422.
                ->send('POST', '/app-manifests/'.rawurlencode($code).'/conversions')
                ->throw()
                ->json();
        } catch (Throwable $exception) {
            return $this->fail(
                'GitHub created the app, but its credentials could not be collected',
                // The code is single-use and spent either way, but a
                // connection error quotes the URL it is part of.
                str_replace($code, '[code]', GitHubError::describe($exception)),
                "The code GitHub hands back works once, so this app cannot be finished from here. Delete it on GitHub ({$apps}), then register again with a new name.",
            );
        }

        try {
            $registration = app(GitHubApp::class)->register(
                $owner,
                $pending['owner'] ?? $conversion['owner']['login'] ?? '',
                (string) ($conversion['id'] ?? ''),
                (string) ($conversion['pem'] ?? ''),
                $conversion['webhook_secret'] ?? null,
            );
        } catch (Throwable $exception) {
            return $this->fail(
                'GitHub created the app, but it could not be verified',
                $exception->getMessage(),
                "Nothing was stored. Delete the app on GitHub ({$apps}), then register again with a new name.",
            );
        }

        // The app exists but reaches nothing until it is installed, which is
        // the next thing anybody registering it wants to do. Nothing is said
        // yet: a notification queued now would only be shown after the round
        // trip through GitHub's install screen, by when it is out of date. The
        // install handshake carries the name instead, and the page that greets
        // the admin on their return mentions it.
        $request->session()->put(self::REGISTERED_KEY, $registration->name);

        // A registration started in the Get started wizard carries on to the
        // install as the wizard's own, so the install comes back to it too.
        return redirect()->route('sources.connect.new', session()->pull(GetStarted::RETURN_KEY, false) ? ['onboarding' => 1] : []);
    }

    /**
     * Back to the page, which keeps the failure on screen in full until the
     * next attempt or until it is dismissed.
     */
    private function fail(string $title, string $detail, string $next): RedirectResponse
    {
        ManageGitHubApp::fail($title, $detail, $next);

        // A registration started in the Get started wizard goes back there,
        // saying what went wrong where the admin is looking.
        if (session()->pull(GetStarted::RETURN_KEY, false)) {
            Notification::make()->danger()->title($title)->body("{$detail} {$next}")->persistent()->send();

            return redirect(GetStarted::getUrl());
        }

        return redirect(GitHubAppResource::getUrl('index'));
    }
}
