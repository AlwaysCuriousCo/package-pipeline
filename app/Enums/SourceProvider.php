<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * The version control providers a Source can connect to.
 *
 * GitHub and GitLab are implemented; Gitea and Bitbucket resolve to stub
 * clients that refuse loudly, so the enum can be complete while the work
 * behind it lands provider by provider.
 */
enum SourceProvider: string implements HasLabel
{
    case Github = 'github';
    case Gitlab = 'gitlab';
    case Gitea = 'gitea';
    case Bitbucket = 'bitbucket';

    public function getLabel(): string
    {
        return match ($this) {
            self::Github => 'GitHub',
            self::Gitlab => 'GitLab',
            self::Gitea => 'Gitea',
            self::Bitbucket => 'Bitbucket',
        };
    }

    /**
     * The API root used when a source does not override it with a base_url,
     * which self-hosted installations (GitHub Enterprise, self-managed
     * GitLab) need to do.
     */
    public function defaultApiUrl(): string
    {
        return match ($this) {
            self::Github => 'https://api.github.com',
            self::Gitlab => 'https://gitlab.com/api/v4',
            self::Gitea => 'https://gitea.com/api/v1',
            self::Bitbucket => 'https://api.bitbucket.org/2.0',
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
            self::Gitlab => 'gitlab.com',
            self::Gitea => 'gitea.com',
            self::Bitbucket => 'bitbucket.org',
        };
    }

    /**
     * The provider a repository host most likely belongs to. Self-hosted
     * instances follow the conventional naming often enough for this to be
     * the right default; a package under a connected source never asks.
     */
    public static function forHost(?string $host): self
    {
        $host = mb_strtolower((string) $host);

        return match (true) {
            str_contains($host, 'gitlab') => self::Gitlab,
            str_contains($host, 'gitea') => self::Gitea,
            str_contains($host, 'bitbucket') => self::Bitbucket,
            default => self::Github,
        };
    }
}
