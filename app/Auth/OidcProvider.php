<?php

namespace App\Auth;

use App\Support\HttpTimeouts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User;

/**
 * A generic OpenID Connect provider: endpoints come from the issuer's
 * discovery document instead of being baked into a driver, so any compliant
 * identity provider works without a package per vendor.
 */
class OidcProvider extends AbstractProvider
{
    /**
     * @var array<int, string>
     */
    protected $scopes = ['openid', 'profile', 'email'];

    protected $scopeSeparator = ' ';

    /**
     * @param  array{authorization_endpoint: string, token_endpoint: string, userinfo_endpoint: string}  $endpoints
     */
    public function __construct(
        Request $request,
        string $clientId,
        string $clientSecret,
        string $redirectUrl,
        private readonly array $endpoints,
    ) {
        parent::__construct($request, $clientId, $clientSecret, $redirectUrl, HttpTimeouts::guzzle());
    }

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase($this->endpoints['authorization_endpoint'], $state);
    }

    protected function getTokenUrl(): string
    {
        return $this->endpoints['token_endpoint'];
    }

    protected function getUserByToken($token): array
    {
        // Also inside the login round trip, and not retried: unlike the
        // discovery document this is read on every login rather than once an
        // hour, so a second attempt would be paid for far more often than it
        // would help.
        return Http::withToken($token)
            ->timeout(HttpTimeouts::LOGIN)
            ->connectTimeout(HttpTimeouts::CONNECT)
            ->acceptJson()
            ->get($this->endpoints['userinfo_endpoint'])
            ->throw()
            ->json();
    }

    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['sub'] ?? null,
            'name' => $user['name'] ?? $user['preferred_username'] ?? null,
            'email' => $user['email'] ?? null,
        ]);
    }
}
