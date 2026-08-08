<?php

namespace App\Auth;

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
        parent::__construct($request, $clientId, $clientSecret, $redirectUrl);
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
        return Http::withToken($token)
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
