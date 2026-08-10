<?php

namespace App\Http\Middleware;

use App\Enums\TokenAbility;
use App\Models\Token;
use App\Providers\AppServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the management API with the same access tokens Composer uses.
 *
 * One credential system, two surfaces: the token travels as a bearer token (or
 * as the HTTP Basic password, so the same `auth.json` entry a Composer client
 * already holds works from curl), and the route names the `api:*` ability it
 * requires.
 *
 * Three things it deliberately does not share with AuthenticateComposer.
 *
 * There is no anonymous path. A public repository is a decision about who may
 * *install* from it; it says nothing about who may enumerate the registry's
 * administration or change it, so every request here presents a credential
 * whatever the repository's `public` flag says.
 *
 * There is no `WWW-Authenticate` challenge. That header exists to make an
 * interactive `composer install` prompt for credentials; sent from an API it
 * only teaches browsers to pop a dialog over a JSON body.
 *
 * There is no failure counter. The Composer endpoints are unthrottled and count
 * failed authentications instead; the API is throttled in front of this
 * middleware, by credential, which bounds guessing without a second ledger.
 *
 * @see AppServiceProvider the `api` limiter
 */
class AuthenticateApi
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $required = TokenAbility::from($ability);

        $plain = $request->bearerToken() ?: $request->getPassword();

        if (blank($plain)) {
            return response()->json([
                'message' => 'Authentication required. Send an access token as "Authorization: Bearer <token>".',
            ], 401);
        }

        $token = Token::findByPlainText($plain);

        // A token whose principal no longer resolves is spent, exactly as it is
        // for Composer: visibility scoping reads it as nobody, which is the one
        // reading that would quietly widen rather than narrow what it reaches.
        if (! $token instanceof Token || $token->isExpired() || $token->tokenable === null) {
            return response()->json([
                'message' => 'The access token is invalid, expired or revoked.',
            ], 401);
        }

        if (! $token->can($required)) {
            return response()->json([
                // Names the ability rather than the endpoint: the fix is to
                // reissue the token, and this is what has to be ticked.
                'message' => "The token \"{$token->name}\" does not have the {$required->value} ability.",
            ], 403);
        }

        $token->markUsed();

        $request->attributes->set('apiToken', $token);

        return $next($request);
    }
}
