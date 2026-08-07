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
        $pending = $request->session()->pull(self::SESSION_KEY);

        $state = $request->query('state');

        // Without a matching state this callback did not originate from a
        // "Connect" click in this session, so there is nothing safe to attach
        // the installation to.
        if (! is_array($pending) || ! is_string($state) || ! hash_equals($pending['state'], $state)) {
            return $this->back(null, 'danger', 'Connection could not be verified', 'The install did not match a pending connection. Start again from the source you want to connect.');
        }

        // A null source_id is the "connect an account we have no source for
        // yet" flow; the source is built from the installation below.
        $source = $pending['source_id'] === null ? null : Source::find($pending['source_id']);

        if ($pending['source_id'] !== null && ! $source instanceof Source) {
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

        return $this->back($source, 'success', "Connected {$source->account}", "{$count} repositories are reachable from this source.");
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
