<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * What an access token may do against the Composer endpoints.
 */
enum TokenAbility: string implements HasLabel
{
    case RepositoryRead = 'repository:read';
    case RepositoryWrite = 'repository:write';

    public function getLabel(): string
    {
        return match ($this) {
            self::RepositoryRead => 'Read — install packages',
            self::RepositoryWrite => 'Write — publish artifact uploads',
        };
    }
}
