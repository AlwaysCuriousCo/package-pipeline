<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Who a GitHub App is registered under, which decides both where its settings
 * page lives and how it can be registered from here.
 */
enum GitHubAppOwner: string implements HasLabel
{
    case User = 'user';
    case Organization = 'organization';
    case Enterprise = 'enterprise';

    public function getLabel(): string
    {
        return match ($this) {
            self::User => 'My personal account',
            self::Organization => 'An organisation',
            self::Enterprise => 'An enterprise',
        };
    }

    /**
     * Whether GitHub accepts an app manifest here. It does for users and
     * organisations, and not for enterprises, where the form can only be
     * prefilled and the credentials have to be brought back by hand.
     */
    public function supportsManifest(): bool
    {
        return $this !== self::Enterprise;
    }

    /**
     * The owner's list of apps on GitHub, where one created by mistake is
     * found and deleted.
     */
    public function appsUrl(?string $owner): string
    {
        return substr($this->newAppUrl($owner), 0, -strlen('/new'));
    }

    /**
     * Where an app's settings live on GitHub — the place to edit it, generate
     * another key, or delete it.
     */
    public function settingsUrl(string $owner, string $slug): string
    {
        return match ($this) {
            self::User => "https://github.com/settings/apps/{$slug}",
            self::Organization => 'https://github.com/organizations/'.rawurlencode($owner)."/settings/apps/{$slug}",
            self::Enterprise => 'https://github.com/enterprises/'.rawurlencode($owner)."/settings/apps/{$slug}",
        };
    }

    /**
     * GitHub's "New GitHub App" page for this owner.
     */
    public function newAppUrl(?string $owner): string
    {
        return match ($this) {
            self::User => 'https://github.com/settings/apps/new',
            self::Organization => 'https://github.com/organizations/'.rawurlencode((string) $owner).'/settings/apps/new',
            self::Enterprise => 'https://github.com/enterprises/'.rawurlencode((string) $owner).'/settings/apps/new',
        };
    }
}
