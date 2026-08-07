<?php

namespace App\Http\Controllers;

use App\Jobs\SyncPackageJob;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as ResponseCode;

/**
 * Turns a push into a sync.
 *
 * GitHub reaches this with no session and no user, so the signature on the
 * request is the whole of the authentication: a body HMAC'd with a secret only
 * GitHub and this app hold. Everything else — which package, which event —
 * comes out of a payload that is only read once the signature clears.
 *
 * There are two ways in because there are two kinds of secret. An installed
 * GitHub App has one webhook of its own, configured once and delivering for
 * every repository in every installation, so it signs with the app's secret
 * and the package is found from the payload. A repository that no installation
 * covers carries its own hook, signed with a secret stored on that package.
 *
 * @see docs/webhooks.md
 */
class GitHubWebhookController extends Controller
{
    /**
     * The events worth waking a sync for. Anything else GitHub sends — and an
     * app-level webhook receives whatever it was subscribed to, which is not
     * ours to narrow — is acknowledged and dropped.
     */
    private const HANDLED_EVENTS = ['push', 'create', 'delete'];

    /**
     * A delivery from the GitHub App's own webhook, covering every
     * installation. The repository it names decides which package syncs.
     */
    public function app(Request $request): Response|JsonResponse
    {
        $secret = config('services.github.app.webhook_secret');

        if (blank($secret)) {
            // Refusing is the honest answer: unverified deliveries are not
            // something to accept quietly, and GitHub's delivery log is where
            // whoever set the webhook up will look.
            return $this->reject(
                'No webhook secret is configured on this registry. Set GITHUB_APP_WEBHOOK_SECRET to the secret on the GitHub App\'s webhook.',
                ResponseCode::HTTP_SERVICE_UNAVAILABLE,
            );
        }

        return $this->handle($request, (string) $secret, package: null);
    }

    /**
     * A delivery from a hook on one repository, signed with the secret stored
     * on the package it was created for.
     */
    public function repository(Request $request, Package $package): Response|JsonResponse
    {
        if (blank($package->webhook_secret)) {
            return $this->reject('This package has no repository webhook.', ResponseCode::HTTP_NOT_FOUND);
        }

        return $this->handle($request, $package->webhook_secret, $package);
    }

    private function handle(Request $request, string $secret, ?Package $package): Response|JsonResponse
    {
        if (! $this->signatureIsValid($request, $secret)) {
            return $this->reject('The signature did not match.', ResponseCode::HTTP_UNAUTHORIZED);
        }

        $event = (string) $request->header('X-GitHub-Event');

        // GitHub sends this once when a webhook is created, and again from the
        // "Redeliver" button, to prove the endpoint is there.
        if ($event === 'ping') {
            return response()->noContent();
        }

        if (! in_array($event, self::HANDLED_EVENTS, true)) {
            return $this->ignore("Nothing here acts on a \"{$event}\" event.");
        }

        // `create` also fires for the repository itself, which names no ref
        // and changes no version.
        if ($request->input('ref_type') === 'repository') {
            return $this->ignore('A repository was created, which publishes no version.');
        }

        $repository = (string) $request->input('repository.full_name');

        if ($repository === '') {
            return $this->reject('The payload named no repository.', ResponseCode::HTTP_UNPROCESSABLE_ENTITY);
        }

        $package ??= Package::forRepositoryPath($repository);

        // An app-level webhook hears from every repository shared with the
        // installation, most of which are not packages here. That is the
        // normal case, not an error.
        if (! $package instanceof Package) {
            return $this->ignore("No package is published from {$repository}.");
        }

        // A hook posting for a repository other than the one it was created
        // for is a misconfiguration worth seeing rather than absorbing.
        if (! $this->matches($package, $repository)) {
            return $this->reject(
                "This webhook belongs to {$package->name}, but the delivery was for {$repository}.",
                ResponseCode::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $package->forceFill(['webhook_received_at' => now()])->save();

        SyncPackageJob::debounced($package);

        return response()->json([
            'status' => 'accepted',
            'package' => $package->name,
            'detail' => "Syncing {$package->name} from {$repository}.",
        ], ResponseCode::HTTP_ACCEPTED);
    }

    /**
     * Whether the delivery really is for this package's repository.
     */
    private function matches(Package $package, string $repository): bool
    {
        try {
            return strcasecmp($package->repositoryPath(), $repository) === 0;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Whether the body carries an HMAC that only the holder of the secret
     * could have produced.
     *
     * The raw body is what GitHub signed, so it is what must be hashed — the
     * decoded payload re-encoded is a different string. hash_equals compares in
     * constant time, which is the difference between a signature check and a
     * signature oracle.
     */
    private function signatureIsValid(Request $request, string $secret): bool
    {
        $signature = (string) $request->header('X-Hub-Signature-256');

        if ($signature === '') {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), $signature);
    }

    /**
     * A delivery that was understood and deliberately not acted on. GitHub
     * counts a 2xx as delivered, which is what this is.
     */
    private function ignore(string $detail): JsonResponse
    {
        return response()->json(['status' => 'ignored', 'detail' => $detail], ResponseCode::HTTP_ACCEPTED);
    }

    /**
     * A delivery that could not be accepted, answered so that it shows up red
     * in the repository's delivery log with the reason attached.
     */
    private function reject(string $detail, int $status): JsonResponse
    {
        return response()->json(['status' => 'rejected', 'detail' => $detail], $status);
    }
}
