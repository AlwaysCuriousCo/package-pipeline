<?php

namespace App\Http\Controllers;

use App\Filament\Resources\Sources\SourceResource;
use App\Models\Package;
use App\Models\Source;
use App\Services\GitHub\GitHubApp;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Drives the GitHub App install handshake that connects a source.
 *
 * The admin is sent to GitHub to choose an organisation and the repositories
 * to share; GitHub returns them here with an installation id, which is all the
 * app needs to mint tokens from then on.
 */
class SourceConnectionController extends Controller
{
    /**
     * The session key holding the pending handshake. It carries the source
     * being connected as well as the CSRF-style state, so a callback can only
     * ever complete the connection the same browser started.
     */
    private const SESSION_KEY = 'sources.github.pending';

    /**
     * Connect a GitHub account that has no source yet. The source is created
     * from whatever GitHub reports back, so nothing has to be typed first.
     */
    public function connectNew(Request $request): RedirectResponse
    {
        return $this->start($request, null);
    }

    /**
     * Reconnect an existing source, or connect one that was added by hand.
     */
    public function connect(Request $request, Source $source): RedirectResponse
    {
        return $this->start($request, $source);
    }

    private function start(Request $request, ?Source $source): RedirectResponse
    {
        $app = app(GitHubApp::class);

        if (! $app->isConfigured()) {
            return $this->back($source, 'danger', 'No GitHub App configured', 'Set GITHUB_APP_ID and GITHUB_APP_PRIVATE_KEY to connect accounts from here. See docs/github-app.md.');
        }

        $state = Str::random(40);

        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'source_id' => $source?->id,
        ]);

        return redirect()->away($app->installationUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $pending = $request->session()->get(self::SESSION_KEY);

        $state = $request->query('state');

        // GitHub sends the admin here without a state whenever the redirect was
        // not one we started: the app's "Redirect on update" fires after any
        // change to an installation, and an account that already has the app
        // lands on its settings page rather than a fresh install, which drops
        // the state on the way. Those are legitimate, so they are treated as
        // the "connect whatever was installed" flow below.
        $unsolicited = $state === null;

        // A state with nothing pending is a replayed or stale callback, and a
        // state that does not match the pending one did not originate from a
        // "Connect" click in this session. Neither is safe to attach an
        // installation to.
        if (! $unsolicited && (! is_array($pending) || ! is_string($state) || ! hash_equals($pending['state'], $state))) {
            $request->session()->forget(self::SESSION_KEY);

            return $this->back(null, 'danger', 'Connection could not be verified', 'The install did not match a pending connection. Start again from the source you want to connect.');
        }

        // Only a callback we can tie to this session consumes the handshake;
        // an unsolicited one must leave a connection already in flight alone.
        if (! $unsolicited) {
            $request->session()->forget(self::SESSION_KEY);
        }

        // A null source_id — and an unsolicited callback, which names no source
        // at all — is the "connect an account we have no source for yet" flow;
        // the source is resolved from the installation below.
        $sourceId = $unsolicited ? null : $pending['source_id'];

        $source = $sourceId === null ? null : Source::find($sourceId);

        if ($sourceId !== null && ! $source instanceof Source) {
            return $this->back(null, 'danger', 'Source no longer exists', 'It was deleted while the install was in progress.');
        }

        $installationId = (int) $request->query('installation_id');

        if ($installationId <= 0) {
            // GitHub sends the admin here without an installation id when they
            // cancel, or when a request to install needs owner approval.
            return $this->back($source, 'warning', 'Nothing was installed', 'GitHub did not return an installation. If your organisation requires owner approval, connect again once the request is approved.');
        }

        try {
            $installation = app(GitHubApp::class)->installation($installationId);
        } catch (Throwable $exception) {
            return $this->back($source, 'danger', 'Connection failed', $exception->getMessage());
        }

        $login = $installation['account']['login'] ?? null;

        // A reconnect has to land on the owner the source already points at.
        // Installing onto a different account would re-credential this source
        // and silently move it away from the packages matched to its owner.
        if ($source?->account && $login && strcasecmp($source->account, $login) !== 0) {
            return $this->back($source, 'danger', 'Wrong account installed', "This source is for \"{$source->account}\", but the app was installed on \"{$login}\". Install it on {$source->account} instead, or connect {$login} from the sources list as its own source.");
        }

        // Installing onto an account another source already holds would break
        // the unique indexes; say so rather than surfacing a database error.
        if ($source && $conflict = Source::conflicting($source, $installationId, $login)) {
            return $this->back($source, 'danger', 'Already connected', "{$login} is connected as \"{$conflict->name}\". Reconnect from that source, or disconnect it first.");
        }

        $source ??= Source::forInstallation($installationId, $installation);

        try {
            $source->completeInstallation($installationId, $installation);
            $count = $source->verify();
        } catch (Throwable $exception) {
            return $this->back($source->exists ? $source : null, 'danger', 'Connection failed', $exception->getMessage());
        }

        $this->adoptMatchingPackages($source);

        // Connecting an account that shares nothing is not a failure — the
        // credentials work — but it is not done either, so it is flagged with
        // what is left to do rather than reported as a success.
        if ($reason = $source->emptyAccessReason($count)) {
            return $this->back($source, 'warning', "Connected {$source->account}, but no repositories", $reason);
        }

        return $this->back(
            $source,
            'success',
            "Connected {$source->account}",
            "{$count} repositories are reachable from this source.{$this->webhookAdvice()}",
        );
    }

    /**
     * A nudge towards the app's webhook, said here because this is the moment
     * an admin is configuring the source and has the app's settings page open.
     *
     * Without it packages still sync themselves — each one falls back to a
     * webhook on its own repository — but that needs a permission the app may
     * not have, and one webhook on the app covers every repository instead.
     */
    private function webhookAdvice(): string
    {
        return app(GitHubApp::class)->hasWebhook()
            ? ''
            : ' Auto-sync is not set up yet: see "Auto-sync" below for the payload URL and a secret to put on the app\'s webhook.';
    }

    /**
     * Claim the packages that were added before this owner was connected.
     *
     * Auto-linking on save cannot see a source that does not exist yet, so
     * connecting one sweeps up the repositories already pointing at it.
     */
    private function adoptMatchingPackages(Source $source): void
    {
        $unlinked = Package::query()->whereNull('source_id')->get();

        foreach ($unlinked as $package) {
            try {
                $owner = strtok($package->repositoryPath(), '/');
            } catch (InvalidArgumentException) {
                continue;
            }

            if (strcasecmp($owner, (string) $source->account) === 0) {
                $package->forceFill(['source_id' => $source->id])->save();
            }
        }
    }

    private function back(?Source $source, string $status, string $title, string $body): RedirectResponse
    {
        Notification::make()->{$status}()->title($title)->body($body)->send();

        return redirect($source
            ? SourceResource::getUrl('view', ['record' => $source])
            : SourceResource::getUrl('index'));
    }
}
