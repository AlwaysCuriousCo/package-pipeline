<?php

namespace App\Auth;

use App\Enums\AuthProvider;
use App\Models\AuthenticationSource;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GithubProvider;
use Laravel\Socialite\Two\GitlabProvider;
use Laravel\Socialite\Two\GoogleProvider;
use RuntimeException;

/**
 * Builds a Socialite provider from an authentication source row — the
 * credentials live in the database, not in config/services.php, so admins
 * add login providers at runtime.
 */
class SsoProviderFactory
{
    public function provider(AuthenticationSource $source): Provider
    {
        $config = [
            'client_id' => $source->client_id,
            'client_secret' => $source->client_secret,
            'redirect' => $source->callbackUrl(),
        ];

        return match ($source->provider) {
            AuthProvider::Github => Socialite::buildProvider(GithubProvider::class, $config),
            AuthProvider::Google => Socialite::buildProvider(GoogleProvider::class, $config),
            AuthProvider::Gitlab => Socialite::buildProvider(GitlabProvider::class, $config),
            AuthProvider::Oidc => new OidcProvider(
                request(),
                $source->client_id,
                $source->client_secret,
                $source->callbackUrl(),
                $this->discover($source),
            ),
        };
    }

    /**
     * Drop a source's cached discovery document, so the next login reads the
     * endpoints from the current discovery URL rather than a cached copy of
     * whatever the URL pointed at before.
     */
    public static function forgetDiscovery(AuthenticationSource $source): void
    {
        cache()->forget(self::discoveryCacheKey($source));
    }

    private static function discoveryCacheKey(AuthenticationSource $source): string
    {
        return "oidc-discovery:{$source->id}";
    }

    /**
     * The issuer's endpoints, read from its discovery document and cached —
     * the document moves at the speed of infrastructure, not of logins.
     *
     * @return array{authorization_endpoint: string, token_endpoint: string, userinfo_endpoint: string}
     */
    private function discover(AuthenticationSource $source): array
    {
        throw_if(blank($source->discovery_url), new RuntimeException(
            "The OIDC source \"{$source->name}\" has no discovery URL.",
        ));

        return cache()->remember(
            self::discoveryCacheKey($source),
            now()->addHour(),
            function () use ($source): array {
                $document = Http::acceptJson()->get($source->discovery_url)->throw()->json();

                foreach (['authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'] as $key) {
                    throw_unless(
                        is_string($document[$key] ?? null),
                        new RuntimeException("The discovery document at {$source->discovery_url} is missing {$key}."),
                    );
                }

                return [
                    'authorization_endpoint' => $document['authorization_endpoint'],
                    'token_endpoint' => $document['token_endpoint'],
                    'userinfo_endpoint' => $document['userinfo_endpoint'],
                ];
            },
        );
    }
}
