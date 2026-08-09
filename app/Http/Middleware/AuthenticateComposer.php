<?php

namespace App\Http\Middleware;

use App\Enums\TokenAbility;
use App\Models\Repository;
use App\Models\Token;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            return $this->challenge($request);
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
