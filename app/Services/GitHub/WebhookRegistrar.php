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
 * Most packages need nothing: their source is an installed GitHub App, and the
 * app's single webhook already delivers for every repository the installation
 * can see. This is the fallback for the rest — a token-based source, or a
 * package carrying its own token — where the only place a hook can live is on
 * the repository itself.
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
    public function register(Package $package): WebhookCoverage
    {
        $coverage = $package->webhookCoverage();

        // Already covered, one way or the other.
        if ($coverage->isActive() || $coverage === WebhookCoverage::Unconfigured) {
            return $coverage;
        }

        $secret = Str::random(40);

        try {
            $id = GitHubClient::for($package)->createWebhook($package->webhookUrl(), $secret);
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
                GitHubClient::for($package)->deleteWebhook($id);
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
            GitHubClient::for($package)->deleteWebhook($package->webhook_id);
        } catch (Throwable $exception) {
            Log::warning('Could not remove the GitHub webhook for a deleted package.', [
                'package' => $package->name,
                'webhook_id' => $package->webhook_id,
                'reason' => $this->reason($exception),
            ]);

            return;
        }

        // The record may be gone already; only touch it while it is still there.
        if ($package->exists) {
            $package->forceFill(['webhook_id' => null, 'webhook_secret' => null])->save();
        }
    }

    /**
     * What is left to do before this package auto-syncs, or null when nothing is.
     */
    public function unmetRequirement(Package $package): ?string
    {
        return match ($package->webhookCoverage()) {
            WebhookCoverage::Application, WebhookCoverage::Repository => null,
            WebhookCoverage::Unconfigured => 'This package is covered by the GitHub App\'s webhook, but no GITHUB_APP_WEBHOOK_SECRET is set here, so deliveries cannot be verified. '
                .'Set the secret on the app\'s webhook and in this app\'s environment — see docs/webhooks.md.',
            WebhookCoverage::Failed => "The repository webhook could not be created: {$package->webhook_error}",
            WebhookCoverage::None => 'No webhook covers this repository, so pushes will not sync it. '
                .'Connect its account as a GitHub App source, or use "Create webhook" to add one to the repository — which needs a credential with admin rights on it.',
        };
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
