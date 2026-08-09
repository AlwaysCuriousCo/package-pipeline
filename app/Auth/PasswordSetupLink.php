<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Password;
use JsonException;

/**
 * The console-issued link that sets an account's first — or forgotten —
 * password.
 *
 * The obvious implementation, a signed URL straight to Filament's reset page,
 * carries the address and the reset token in the query string. But
 * `?email=someone@example.com&token=<64 hex>` arriving at a page full of
 * password inputs is the shape of a credential-harvesting kit, and Safe
 * Browsing scores it as one: on a host with no reputation yet (a fresh
 * deployment, a preview domain, a tunnel) Chrome puts a full-page "Dangerous
 * site" interstitial in front of the single link that gets the first admin in.
 *
 * So nothing identifying travels in the URL. Address, token and expiry are
 * sealed into one opaque path segment with the app key; opening it moves them
 * into the session and redirects to a bare reset page. Sealed rather than
 * stored, because the link is typically printed by a deploy hook running on a
 * container that is gone by the time anybody clicks it.
 */
class PasswordSetupLink
{
    /**
     * Where the claimed address and token wait between the link being opened
     * and the reset form being submitted.
     */
    public const SESSION_KEY = 'password-setup';

    /**
     * How long a printed link stays usable. Deliberately much shorter than the
     * password broker's own expiry: the URL lands in consoles and deploy logs,
     * so it must go stale quickly.
     */
    public const TTL_MINUTES = 5;

    /**
     * Issue a link that will set this user's password.
     */
    public static function for(User $user): string
    {
        return route('password-setup', [
            'payload' => static::seal([
                'email' => $user->getEmailForPasswordReset(),
                'token' => Password::broker()->createToken($user),
                'expires' => now()->addMinutes(static::TTL_MINUTES)->getTimestamp(),
            ]),
        ]);
    }

    /**
     * The address and reset token a link carries, or null when it was
     * tampered with, issued under a different app key, or has expired.
     *
     * @return array{email: string, token: string}|null
     */
    public static function open(string $payload): ?array
    {
        $claim = static::unseal($payload);

        if ($claim === null) {
            return null;
        }

        if (now()->getTimestamp() > (int) $claim['expires']) {
            return null;
        }

        return [
            'email' => (string) $claim['email'],
            'token' => (string) $claim['token'],
        ];
    }

    /**
     * @param  array{email: string, token: string, expires: int}  $claim
     */
    private static function seal(array $claim): string
    {
        // Laravel's ciphertext is standard base64, whose "/" would split the
        // path segment in two. base64url keeps it to one.
        return rtrim(strtr(Crypt::encryptString(json_encode($claim, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * @return array{email: string, token: string, expires: int}|null
     */
    private static function unseal(string $payload): ?array
    {
        try {
            $claim = json_decode(
                Crypt::decryptString(strtr($payload, '-_', '+/')),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (DecryptException|JsonException) {
            return null;
        }

        if (! is_array($claim) || ! isset($claim['email'], $claim['token'], $claim['expires'])) {
            return null;
        }

        return $claim;
    }
}
