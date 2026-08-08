<?php

namespace App\Services\GitHub;

use App\Enums\WebhookCoverage;
use App\Models\Package;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Gives a package a way to hear about its own pushes.
 *
 * Creating a hook on the repository is the general answer, and the one every
 * package gets: it needs nothing configured beforehand and works the same for
 * a token-based source as for an installed app.
 *
 * The exception is worth taking when it is available. A GitHub App has one
 * webhook of its own that already delivers for every repository in every
 * installation, so a package under an installed app with that webhook set up
 * is covered before it is created — and a hook on the repository would only
 * mean the same push arriving twice.
 */
class WebhookRegistrar
{
    /**
     * Ensure the package has a delivery path, creating a repository hook when
     * that is what it takes.
     *
     * Never throws. A package whose hook could not be created is still a
     * perfectly good package — it just syncs when asked rather than when
     * pushed — so the reason is recorded on the record and surfaced in the
     * panel instead of failing whatever was being done at the time.
     *
     * @return WebhookCoverage the coverage the package ended up with
     */
    /**
     * Make GitHub match what the package asks for.
     *
     * The toggle is the intent and this is what carries it out, in both
     * directions: switching auto-sync on creates whatever is missing,
     * switching it off takes the repository hook back down rather than
     * leaving GitHub posting to an endpoint that now ignores it.
     */
    public function reconcile(Package $package): WebhookCoverage
    {
        if (! $package->webhook_enabled) {
            $this->deregister($package);

            return WebhookCoverage::Disabled;
        }

        return $this->register($package);
    }

    public function register(Package $package): WebhookCoverage
    {
        $coverage = $package->webhookCoverage();

        // Nothing to arrange for a package that has asked not to be synced.
        if ($coverage === WebhookCoverage::Disabled) {
            return $coverage;
        }

        // Already covered, one way or the other. Everything else — including
        // an app-installed package on a registry whose app webhook is not set
        // up — falls through and gets a hook of its own, because a package
        // that hears about its pushes beats one waiting on configuration.
        if ($coverage->isActive()) {
            return $coverage;
        }

        $secret = Str::random(40);

        try {
            $id = $package->client()->createWebhook($package->webhookUrl(), $secret);
        } catch (Throwable $exception) {
            $package->forceFill(['webhook_error' => $this->reason($exception)])->save();

            return WebhookCoverage::Failed;
        }

        try {
            $package->forceFill([
                'webhook_id' => $id,
                'webhook_secret' => $secret,
                'webhook_error' => null,
            ])->save();
        } catch (Throwable $exception) {
            // The hook now exists on GitHub but this record will not remember
            // it, so a retry would stack a second hook on the repository —
            // and the secret for this one is lost with the save. Take it back
            // down before reporting the failure.
            try {
                $package->client()->deleteWebhook($id);
            } catch (Throwable $cleanup) {
                Log::warning('Could not remove a GitHub webhook that failed to persist locally.', [
                    'package' => $package->name,
                    'webhook_id' => $id,
                    'reason' => $this->reason($cleanup),
                ]);
            }

            // Whatever refused the first save may refuse this one too; the
            // contract is to never throw, and the hook is already gone.
            rescue(fn () => $package->forceFill([
                'webhook_id' => null,
                'webhook_secret' => null,
                'webhook_error' => $this->reason($exception),
            ])->save(), report: false);

            return WebhookCoverage::Failed;
        }

        return WebhookCoverage::Repository;
    }

    /**
     * Remove the repository hook, if this package has one.
     *
     * Called as a package is deleted, where there is nowhere left to report a
     * failure to and nothing to abort — a hook GitHub keeps posting to a dead
     * URL is a nuisance, not a fault, so it is logged and let go.
     */
    public function deregister(Package $package): void
    {
        if ($package->webhook_id === null) {
            return;
        }

        try {
            $package->client()->deleteWebhook($package->webhook_id);
        } catch (Throwable $exception) {
            Log::warning('Could not remove the GitHub webhook for a deleted package.', [
                'package' => $package->name,
                'webhook_id' => $package->webhook_id,
                'reason' => $this->reason($exception),
            ]);

            return;
        }

        // The record may be gone already; only touch it while it is still
        // there. An old failure goes with the hook, so switching auto-sync
        // back on starts from a clean slate rather than from last time.
        if ($package->exists) {
            $package->forceFill([
                'webhook_id' => null,
                'webhook_secret' => null,
                'webhook_error' => null,
            ])->save();
        }
    }

    /**
     * What is left to do before this package auto-syncs, or null when nothing is.
     */
    public function unmetRequirement(Package $package): ?string
    {
        return match ($package->webhookCoverage()) {
            // Off on purpose is not something left undone.
            WebhookCoverage::Application, WebhookCoverage::Repository, WebhookCoverage::Disabled => null,
            WebhookCoverage::Failed => "The repository webhook could not be created: {$package->webhook_error} {$this->remedy($package)}",
            WebhookCoverage::None => "No webhook covers this repository, so pushes will not sync it. {$this->remedy($package)}",
        };
    }

    /**
     * How to get this particular package syncing itself.
     *
     * Creating a hook needs write access to the repository's webhooks, which
     * neither a read-only token nor an app installed for Contents alone has.
     * Where an app is involved there is a second way out that needs no
     * repository permission at all, and it is usually the better one — it
     * covers every repository the installation can see, not just this one.
     */
    private function remedy(Package $package): string
    {
        if ($package->source?->usesInstallation()) {
            return 'Either turn on the GitHub App\'s own webhook and set GITHUB_APP_WEBHOOK_SECRET, which covers every repository in the installation at once, '
                .'or give the app "Webhooks: Read and write" so it can create one on the repository. See docs/webhooks.md.';
        }

        return 'The credential this package authenticates with needs write access to the repository\'s webhooks '
            .'(a fine-grained token with Webhooks: Read and write, or a classic token with admin:repo_hook), '
            .'then use "Create webhook" to try again.';
    }

    /**
     * GitHub's own wording where there is any, since "403 Forbidden" alone
     * never explains that the token lacks admin rights on the repository.
     */
    private function reason(Throwable $exception): string
    {
        return Str::limit($exception->getMessage(), 500);
    }
}
