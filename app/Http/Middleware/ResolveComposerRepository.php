<?php

namespace App\Http\Middleware;

use App\Models\Repository;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves which Composer repository a request is addressed to.
 *
 * The Composer routes are mounted twice — at the site root for the default
 * repository and under /r/{repositoryPath} for named ones — and every
 * endpoint scopes its queries to the repository resolved here, so the two
 * mounts share one controller.
 *
 * A repository with a host of its own answers at that host's root as well, so
 * the root mount there resolves to it rather than to the default repository.
 */
class ResolveComposerRepository
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->route('repositoryPath');

        $repository = Repository::forHost($request->getHost());

        if ($repository instanceof Repository) {
            // A repository's own host serves that repository and nothing
            // else: /r/{path} there would put one product's name on another's
            // packages.
            abort_if($path !== null, 404, 'Only this host\'s own repository is served here.');
        } else {
            $repository = Repository::forPath($path);

            abort_unless(
                $repository instanceof Repository,
                404,
                "No Composer repository is served at /r/{$path}.",
            );

            if ($path !== null) {
                $repository->addressedByPath();
            }
        }

        if ($path !== null) {
            // Controller methods receive route parameters positionally, and
            // both mounts must dispatch with identical signatures — the
            // repository travels as a request attribute instead.
            $request->route()->forgetParameter('repositoryPath');
        }

        $request->attributes->set('composerRepository', $repository);

        return $next($request);
    }
}
