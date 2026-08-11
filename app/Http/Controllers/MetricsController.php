<?php

namespace App\Http\Controllers;

use App\Services\RegistryMetrics;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The Prometheus scrape endpoint.
 *
 * **Off unless an operator turns it on**, and that default is a decision rather
 * than caution. A metrics endpoint is conventionally unauthenticated because it
 * conventionally sits on an internal network — but this app is a Composer
 * registry, which means it is routinely published to the internet so that CI
 * can reach it. Shipping an on-by-default endpoint that tells any passer-by how
 * many private packages exist, how much traffic they get and whether the queue
 * is backed up would widen every existing installation's exposure on upgrade,
 * silently, for a feature nobody had asked for.
 *
 * So `METRICS_ENABLED=false` is the shipped state, and enabling it is one line.
 * `METRICS_TOKEN` is then optional: set, it is a bearer token every scrape must
 * present; unset, the endpoint is open, which is the right answer when the port
 * genuinely is not routable and the wrong one whenever there is any doubt.
 *
 * A disabled endpoint answers 404 rather than 403 — there is nothing here, and
 * saying "there is something here you may not have" is a worse answer to a
 * stranger than saying nothing.
 *
 * @see docs/metrics.md
 * @see RegistryMetrics for what is measured and why nothing is per-package
 */
class MetricsController extends Controller
{
    public function __invoke(Request $request, RegistryMetrics $metrics): Response
    {
        abort_unless((bool) config('registry.metrics.enabled'), 404);

        $token = config('registry.metrics.token');

        if (filled($token)) {
            // Constant time, because a bearer token compared with == is a
            // bearer token being handed out one byte at a time — the same
            // reason GitHubWebhookController compares signatures this way.
            abort_unless(
                hash_equals((string) $token, (string) $request->bearerToken()),
                401,
                'This metrics endpoint requires a bearer token.',
            );
        }

        return response($metrics->render(), 200, [
            // The version the exposition format has been on since 2014, and
            // what every Prometheus release parses.
            'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
            // A scrape is a point-in-time reading; a cached one replayed later
            // would be a lie about when it was taken. The service does its own
            // caching, deliberately, on its own terms.
            'Cache-Control' => 'no-store',
        ]);
    }
}
