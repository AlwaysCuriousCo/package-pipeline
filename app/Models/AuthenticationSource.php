<?php

namespace App\Models;

use App\Enums\AuthProvider;
use Database\Factories\AuthenticationSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A login provider configured at runtime: the OAuth client this registry is
 * registered as with an identity provider, plus the rules for who may sign
 * in — and who may sign *up* — through it.
 */
#[Fillable(['name', 'provider', 'client_id', 'client_secret', 'discovery_url', 'active', 'allow_registration', 'allowed_domains', 'default_role'])]
class AuthenticationSource extends Model
{
    /** @use HasFactory<AuthenticationSourceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => AuthProvider::class,
            'client_secret' => 'encrypted',
            'active' => 'boolean',
            'allow_registration' => 'boolean',
            'allowed_domains' => 'array',
        ];
    }

    /**
     * Where the identity provider sends the browser back to — the redirect
     * URI to register on the OAuth client.
     */
    public function callbackUrl(): string
    {
        return route('sso.callback', $this);
    }

    /**
     * Whether an email address passes this source's domain allowlist. An
     * empty list allows every domain — the allowlist narrows registration,
     * it does not exist to be mandatory.
     */
    public function allowsDomain(string $email): bool
    {
        $domains = array_filter((array) $this->allowed_domains);

        if ($domains === []) {
            return true;
        }

        $domain = mb_strtolower(Str::after($email, '@'));

        return in_array($domain, array_map(mb_strtolower(...), $domains), true);
    }
}
