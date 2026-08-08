<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The login providers an authentication source can speak. OIDC is the
 * general case (any issuer with a discovery document); the rest are the
 * name-brand OAuth providers Socialite ships drivers for.
 */
enum AuthProvider: string implements HasLabel
{
    case Oidc = 'oidc';
    case Github = 'github';
    case Google = 'google';
    case Gitlab = 'gitlab';

    public function getLabel(): string
    {
        return match ($this) {
            self::Oidc => 'OIDC',
            self::Github => 'GitHub',
            self::Google => 'Google',
            self::Gitlab => 'GitLab',
        };
    }
}
