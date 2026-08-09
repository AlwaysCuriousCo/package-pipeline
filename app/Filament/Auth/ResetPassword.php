<?php

namespace App\Filament\Auth;

use App\Auth\PasswordSetupLink;
use Filament\Auth\Http\Responses\Contracts\PasswordResetResponse;
use Filament\Auth\Pages\PasswordReset\ResetPassword as BaseResetPassword;
use SensitiveParameter;

/**
 * Filament's reset form, taught to accept its address and token from the
 * session as well as the query string.
 *
 * That is what lets a console-issued link be an opaque path segment rather
 * than `?email=…&token=…`, which Safe Browsing reads as a phishing page; see
 * {@see PasswordSetupLink}. The query string still works, so the emailed
 * "forgot password" link is untouched.
 */
class ResetPassword extends BaseResetPassword
{
    public function mount(?string $email = null, #[SensitiveParameter] ?string $token = null): void
    {
        $claim = session(PasswordSetupLink::SESSION_KEY);

        if (! is_array($claim)) {
            $claim = [];
        }

        parent::mount(
            $email ?? request()->query('email') ?? ($claim['email'] ?? null),
            $token ?? request()->query('token') ?? ($claim['token'] ?? null),
        );
    }

    public function resetPassword(): ?PasswordResetResponse
    {
        $response = parent::resetPassword();

        // The broker has already burned the token; leaving the claim behind
        // would only produce a confusing "invalid token" on the next visit.
        if ($response !== null) {
            session()->forget(PasswordSetupLink::SESSION_KEY);
        }

        return $response;
    }
}
