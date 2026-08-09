<?php

namespace App\Http\Middleware;

use App\Enums\TokenAbility;
use App\Models\Repository;
use App\Models\Token;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates Composer clients with access tokens.
 *
 * The token travels as the HTTP Basic password (any username) or as a bearer
 * token. Reads on a public repository pass without one; everything else needs
 * a live token holding the required ability. Runs after
 * ResolveComposerRepository, whose resolved repository decides the public
 * bypass.
 */
class AuthenticateComposer
{
    /**
     * Failed authentications one address may spend in a minute before the
     * failures start being answered 429 instead of 401.
     *
     * Only failures are counted, and a credential that works is never measured
     * against this at all — a CI fleet and an office both arrive from a single
     * egress address, and one machine left holding a revoked token must not be
     * able to lock out everything it shares that address with. A success does
     * not clear the count either: one working token would otherwise wipe the
     * record of everything failing beside it.
     *
     * Tokens are forty random characters behind a sha256, so this was never
     * what stands between a guesser and the registry. It is here to put a
     * ceiling on the guessing and a mark in the logs where there was neither.
     */
    private const MAX_FAILURES_PER_MINUTE = 30;

    /**
     * @param  'read'|'write'  $ability
     */
    public function handle(Request $request, Closure $next, string $ability = 'read'): Response
    {
        $required = $ability === 'write' ? TokenAbility::RepositoryWrite : TokenAbility::RepositoryRead;

        $plain = $request->getPassword() ?: $request->bearerToken();

        if (blank($plain)) {
            /** @var Repository|null $repository */
            $repository = $request->attributes->get('composerRepository');

            if ($required === TokenAbility::RepositoryRead && $repository?->public) {
                return $next($request);
            }

            return $this->challenge($request);
        }

        $token = Token::findByPlainText($plain);

        // A presented credential is always checked, public repository or not:
        // a CI system with a revoked token should hear about it as a 401, not
        // keep working by accident until the repository goes private. A token
        // whose principal no longer resolves is spent too — package scoping
        // reads it as nobody and would hand it every public package.
        if (! $token instanceof Token || $token->isExpired() || $token->tokenable === null) {
            return $this->rejectCredential($request);
        }

        if (! $token->can($required)) {
            return response()->json([
                'message' => "The token \"{$token->name}\" does not have the {$required->value} ability.",
            ], 403);
        }

        $token->markUsed();

        $request->attributes->set('composerToken', $token);

        return $next($request);
    }

    /**
     * A presented credential that did not authenticate: counted against the
     * address that sent it, and answered 429 once that address has spent its
     * budget. Only credentials reach here — an anonymous request to a private
     * repository is a miss, not a guess, and costs no lookup to answer.
     */
    private function rejectCredential(Request $request): JsonResponse
    {
        $key = 'composer-auth:'.$request->ip();

        RateLimiter::hit($key);

        if (! RateLimiter::tooManyAttempts($key, self::MAX_FAILURES_PER_MINUTE)) {
            return $this->challenge($request);
        }

        $seconds = RateLimiter::availableIn($key);

        // Deliberately not a 401, and deliberately without the Basic challenge
        // header: an interactive `composer install` reads that challenge as
        // "those credentials are wrong" and prompts for new ones, when the
        // credentials are beside the point and waiting is the answer. The
        // message says as much, because a CI log is where it will be read.
        return response()->json([
            'message' => "Too many failed authentication attempts from this address. This is a rate limit, not a rejected token — retry in {$seconds} seconds.",
        ], 429, ['Retry-After' => $seconds]);
    }

    /**
     * The 401 that tells a human how to fix it. The Basic challenge is also
     * what makes an interactive `composer install` prompt for credentials.
     */
    private function challenge(Request $request): JsonResponse
    {
        $host = $request->getHttpHost();

        return response()->json([
            'message' => 'Authentication required. Create an access token in the admin panel, then run: '
                ."composer config http-basic.{$host} token <your-token>",
        ], 401, ['WWW-Authenticate' => 'Basic realm="Composer repository"']);
    }
}
