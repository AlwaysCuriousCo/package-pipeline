<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates WordPress sites exactly as Composer clients are authenticated
 * — the same tokens, as the Basic password or a bearer, needing the same read
 * ability — and changes only what a refusal looks like.
 *
 * A site's update check merges whatever this registry answers into the
 * update transient. A refusal carrying a message is a document core would try
 * to read as a list of updates, so every refusal here is an empty JSON object
 * under a 401: no token, a bad one, and one without the read ability (which
 * AuthenticateComposer answers 403) alike. A rate-limited address keeps its
 * 429 and Retry-After, with the same empty body.
 *
 * @see AuthenticateComposer where the decisions are made
 */
class AuthenticateWordPress
{
    public function __construct(private readonly AuthenticateComposer $composer) {}

    public function handle(Request $request, Closure $next): Response
    {
        $passed = false;

        $response = $this->composer->handle($request, function (Request $request) use ($next, &$passed): Response {
            $passed = true;

            return $next($request);
        });

        // Whatever the controller answered, it answered; only a refusal by
        // the authentication itself is rewritten.
        if ($passed) {
            return $response;
        }

        $limited = $response->getStatusCode() === 429;

        return response()->json(
            new stdClass,
            $limited ? 429 : 401,
            $limited ? ['Retry-After' => $response->headers->get('Retry-After')] : [],
        );
    }
}
