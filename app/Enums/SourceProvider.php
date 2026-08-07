<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The version control providers a Source can connect to.
 *
 * Only GitHub is implemented; the enum exists so that the rest of the app is
 * written against a provider rather than assuming GitHub everywhere.
 */
enum SourceProvider: string implements HasLabel
{
    case Github = 'github';

    public function getLabel(): string
    {
        return match ($this) {
            self::Github => 'GitHub',
        };
    }

    /**
     * The API root used when a source does not override it with a base_url,
     * which self-hosted installations (GitHub Enterprise) need to do.
     */
    public function defaultApiUrl(): string
    {
        return match ($this) {
            self::Github => 'https://api.github.com',
        };
    }

    /**
     * The host that owns a repository on this provider, used to match a
     * package's repository URL back to a source.
     */
    public function host(): string
    {
        return match ($this) {
            self::Github => 'github.com',
        };
    }
}
