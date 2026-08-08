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
 */
class ResolveComposerRepository
{
    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->route('repositoryPath');

        $repository = Repository::forPath($path);

        abort_unless(
            $repository instanceof Repository,
            404,
            "No Composer repository is served at /r/{$path}.",
        );

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
