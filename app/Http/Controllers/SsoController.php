<?php

namespace App\Http\Controllers;

use App\Auth\SsoProviderFactory;
use App\Enums\AuthProvider;
use App\Models\AuthenticationSource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Contracts\User as Identity;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * The SSO round trip: off to the identity provider, back with an identity,
 * and into the panel as whichever user that identity resolves to.
 */
class SsoController extends Controller
{
    public function __construct(private readonly SsoProviderFactory $providers) {}

    public function redirect(AuthenticationSource $source): SymfonyRedirect
    {
        abort_unless($source->active, 404);

        return $this->providers->provider($source)->redirect();
    }

    public function callback(AuthenticationSource $source): RedirectResponse
    {
        abort_unless($source->active, 404);

        try {
            $identity = $this->providers->provider($source)->user();
        } catch (Throwable) {
            // Cancelled consent, a stale state token, a provider outage — the
            // user's next move is the same for all of them.
            return $this->refuse('Sign-in failed or was cancelled. Try again.');
        }

        $externalId = (string) $identity->getId();
        $email = $identity->getEmail();

        $user = User::query()
            ->where('authentication_source_id', $source->id)
            ->where('external_id', $externalId)
            ->first();

        // Fall back to the email for accounts that predate the source, and
        // bind the identity so renaming an email at the provider later does
        // not mint a second account.
        if (! $user instanceof User && filled($email)) {
            $existing = User::query()->where('email', $email)->first();

            if ($existing instanceof User) {
                // Never rebind an already-bound account: that would let one
                // provider quietly take over another's users.
                if ($existing->external_id !== null) {
                    return $this->refuse('That address already signs in through a different provider.');
                }

                // Adopting an unclaimed account on an asserted address is a
                // takeover: an admin can point an OIDC source at any issuer,
                // and an issuer can claim any address it likes. The provider
                // has to stand behind it, and the source has to be trusted
                // with the domain — the allowlist gates who may register, and
                // inheriting an account is at least as much of a grant.
                if (! $this->vouchedForEmail($source, $identity) || ! $source->allowsDomain($email)) {
                    return $this->refuse('An account already uses that address. An admin must link it to this provider.');
                }

                $existing->forceFill([
                    'authentication_source_id' => $source->id,
                    'external_id' => $externalId,
                ])->save();

                $user = $existing;
            }
        }

        if (! $user instanceof User) {
            $user = $this->register($source, $identity, $externalId, $email);

            if ($user === null) {
                return $this->refuse($source->allow_registration
                    ? 'That account is not allowed to register here.'
                    : 'This sign-in is limited to existing accounts.');
            }
        }

        if (! $user->canAccessPanel(Filament::getPanel('admin'))) {
            return $this->refuse('Your account has no role on this registry yet — ask an admin to assign one.');
        }

        Auth::login($user, remember: true);
        session()->regenerate();

        return redirect()->intended(Filament::getPanel('admin')->getUrl());
    }

    /**
     * Whether the provider itself stands behind the address, rather than
     * passing on whatever the principal put in its profile.
     */
    private function vouchedForEmail(AuthenticationSource $source, Identity $identity): bool
    {
        // Socialite's name-brand drivers hand back an address the provider has
        // already confirmed — GitHub's picks the primary *verified* one off
        // /user/emails, and Google and GitLab only publish confirmed addresses.
        if ($source->provider !== AuthProvider::Oidc) {
            return true;
        }

        // OIDC is the general case, so nothing about the issuer is known in
        // advance and only the claim can answer for the address. Some issuers
        // send it as a string; an absent claim is not a promise.
        $claims = $identity instanceof AbstractUser ? $identity->getRaw() : [];
        $verified = $claims['email_verified'] ?? null;

        return $verified === true || $verified === 'true';
    }

    /**
     * Provision the account an unknown identity is entitled to, or null when
     * it is not entitled to one.
     */
    private function register(AuthenticationSource $source, Identity $identity, string $externalId, ?string $email): ?User
    {
        if (! $source->allow_registration || blank($email) || ! $source->allowsDomain($email)) {
            return null;
        }

        // The same promise adoption asks for. A domain allowlist says which
        // addresses may become accounts here, and it can only mean that if
        // the address is the provider's to assert — an OIDC issuer that lets
        // a principal type an unverified address into its own profile would
        // otherwise hand out an account on any allowed domain for the asking,
        // stamped verified below on the strength of that same claim.
        if (! $this->vouchedForEmail($source, $identity)) {
            return null;
        }

        $name = $identity->getName();

        $user = User::query()->create([
            'name' => $name ?: Str::headline(Str::before($email, '@')),
            'email' => $email,
            // Never learned by anyone; this account signs in through SSO.
            'password' => Str::password(64),
        ]);

        $user->forceFill([
            'authentication_source_id' => $source->id,
            'external_id' => $externalId,
            // The identity provider vouched for the address.
            'email_verified_at' => now(),
        ])->save();

        if (filled($source->default_role)) {
            $user->assignRole($source->default_role);
        }

        return $user;
    }

    private function refuse(string $message): RedirectResponse
    {
        return redirect()
            ->route('filament.admin.auth.login')
            ->with('sso_error', $message);
    }
}
