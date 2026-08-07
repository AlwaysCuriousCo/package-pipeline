<?php

namespace App\Filament\Resources\Sources\Actions;

use App\Services\GitHub\GitHubApp;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

class ConnectGitHubAction
{
    /**
     * The primary way to add a source: no form, straight to GitHub's install
     * screen. The account, its type and the repository list all come back from
     * the installation, so the source is created without anything being typed.
     */
    public static function make(): Action
    {
        return Action::make('connectGithub')
            ->label('Connect GitHub account')
            ->icon(Heroicon::OutlinedLink)
            ->color('primary')
            ->url(fn (): ?string => static::isAvailable() ? route('sources.connect.new') : null)
            ->disabled(fn (): bool => ! static::isAvailable())
            ->tooltip(fn (): ?string => static::isAvailable()
                ? null
                : 'No GitHub App is registered for this instance. Set GITHUB_APP_ID and GITHUB_APP_PRIVATE_KEY — see docs/github-app.md.');
    }

    public static function isAvailable(): bool
    {
        return app(GitHubApp::class)->isConfigured();
    }
}
