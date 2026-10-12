<?php

namespace App\Http\Middleware;

use App\Models\Repository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a repository's own host to that repository's pages.
 *
 * The protocol endpoints already answer only for the host's repository (see
 * ResolveComposerRepository), and they sit outside the groups this runs in.
 * What is left is everything else the app serves — the panel, billing,
 * signup, pricing, sign-in, the webhooks — none of which belongs under a
 * product's name. A page fetch goes to the same path on the registry's own
 * address; anything else is a 404, since a redirected POST arrives as a GET.
 */
class ConfineRepositoryHosts
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Repository::forHost($request->getHost()) === null) {
            return $next($request);
        }

        $route = $request->route();

        // The repository's own pages, which resolve the repository the same
        // way the protocol endpoints do, plus the two documents crawlers ask
        // every host for.
        if ($route !== null && (
            in_array(ResolveComposerRepository::class, $route->gatherMiddleware(), true)
            || in_array($route->getName(), ['sitemap', 'robots'], true)
        )) {
            return $next($request);
        }

        abort_unless($request->isMethodSafe(), 404);

        return redirect()->away(rtrim((string) config('app.url'), '/').$request->getRequestUri());
    }
}
