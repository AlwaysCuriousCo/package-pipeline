<?php

namespace App\Http\Controllers;

use App\Auth\SsoProviderFactory;
use App\Models\AuthenticationSource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
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
        // not mint a second account. Never rebind an already-bound account:
        // that would let one provider quietly take over another's users.
        if (! $user instanceof User && filled($email)) {
            $user = User::query()->where('email', $email)->first();

            if ($user instanceof User && $user->external_id === null) {
                $user->forceFill([
                    'authentication_source_id' => $source->id,
                    'external_id' => $externalId,
                ])->save();
            }
        }

        if (! $user instanceof User) {
            $user = $this->register($source, $externalId, $email, $identity->getName());

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
     * Provision the account an unknown identity is entitled to, or null when
     * it is not entitled to one.
     */
    private function register(AuthenticationSource $source, string $externalId, ?string $email, ?string $name): ?User
    {
        if (! $source->allow_registration || blank($email) || ! $source->allowsDomain($email)) {
            return null;
        }

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
