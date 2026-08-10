<?php

namespace App\Http\Controllers;

use App\Jobs\SyncPackageJob;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as ResponseCode;

/**
 * Turns a GitLab push into a sync.
 *
 * GitLab authenticates deliveries differently from GitHub: instead of an HMAC
 * over the body it replays the hook's secret verbatim in X-Gitlab-Token, so
 * verification is a constant-time comparison against the secret stored on the
 * package. Only the per-repository leg exists — there is no GitLab equivalent
 * of the GitHub App's account-wide webhook.
 */
class GitLabWebhookController extends Controller
{
    /**
     * The events that can change what a version resolves to. Anything else
     * the hook is subscribed to is acknowledged and dropped.
     */
    private const HANDLED_EVENTS = ['Push Hook', 'Tag Push Hook'];

    public function repository(Request $request, Package $package): JsonResponse
    {
        if (blank($package->webhook_secret)) {
            return $this->reject('This package has no repository webhook.', ResponseCode::HTTP_NOT_FOUND);
        }

        if (! hash_equals($package->webhook_secret, (string) $request->header('X-Gitlab-Token'))) {
            return $this->reject('The token did not match.', ResponseCode::HTTP_UNAUTHORIZED);
        }

        $event = (string) $request->header('X-Gitlab-Event');

        if (! in_array($event, self::HANDLED_EVENTS, true)) {
            return response()->json([
                'status' => 'ignored',
                'detail' => "Nothing here acts on a \"{$event}\" event.",
            ], ResponseCode::HTTP_ACCEPTED);
        }

        // Every package published from the project, not only the one the hook
        // was created for: a project may hold several — a monorepo does by
        // design — and a push says nothing about which of them it touched. The
        // GitHub controller resolves the same set for the same reason; see the
        // comment there. Falls back to the package itself so a row whose
        // derived path is somehow missing still syncs on its own hook.
        $packages = Package::allForRepositoryPath((string) $package->repository_path);

        if ($packages->isEmpty()) {
            $packages = $package->newCollection([$package]);
        }

        $enabled = $packages->filter(fn (Package $target): bool => $target->webhook_enabled);

        if ($enabled->isEmpty()) {
            return response()->json([
                'status' => 'ignored',
                'detail' => "Auto-sync is switched off for {$package->name}.",
            ], ResponseCode::HTTP_ACCEPTED);
        }

        foreach ($enabled as $target) {
            $target->forceFill(['webhook_received_at' => now()])->save();

            SyncPackageJob::debounced($target);
        }

        $names = $enabled->pluck('name')->implode(', ');

        return response()->json([
            'status' => 'accepted',
            'package' => $names,
            'detail' => "Syncing {$names}.",
        ], ResponseCode::HTTP_ACCEPTED);
    }

    private function reject(string $detail, int $status): JsonResponse
    {
        return response()->json(['status' => 'rejected', 'detail' => $detail], $status);
    }
}
