<?php

namespace App\Filament\Resources\Sources\Actions;

use App\Filament\Resources\GitHubApps\Actions\RegisterGitHubAppAction;
use App\Filament\Resources\GitHubApps\GitHubAppResource;
use App\Services\GitHub\GitHubApp;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ConnectGitHubAction
{
    /**
     * The primary way to add a source: no form, straight to GitHub's install
     * screen. The account, its type and the repository list all come back from
     * the installation, so the source is created without anything being typed.
     *
     * On an instance with no app yet, the same button registers one first and
     * then carries straight on to the install screen, so a first run never
     * meets a disabled button. Only an environment that names an app but
     * leaves out its key has nothing to offer, and says so.
     */
    public static function make(): Action
    {
        if (! static::isAvailable() && ! app(GitHubApp::class)->configuredFromEnvironment()) {
            return RegisterGitHubAppAction::make('connectGithub')
                ->label('Connect GitHub account')
                ->icon(Heroicon::OutlinedLink)
                ->color('primary')
                ->modalHeading('Connect a GitHub account')
                ->modalDescription('First, a one-time step: this registry needs its own GitHub App, its read-only identity on GitHub, which GitHub creates from the details below. Then GitHub asks which account to install it on; installing is the part you repeat for each account. On GitHub, choose “Only select repositories” and pick the ones to serve.');
        }

        return Action::make('connectGithub')
            ->label('Connect GitHub account')
            ->icon(Heroicon::OutlinedLink)
            ->color('primary')
            ->url(fn (): ?string => static::isAvailable() ? route('sources.connect.new') : null)
            ->disabled(fn (): bool => ! static::isAvailable())
            ->tooltip(fn (): ?string => static::isAvailable()
                ? null
                : 'GITHUB_APP_ID or GITHUB_APP_PRIVATE_KEY is set without the other. Set both, or neither to register the app from here — see docs/github-app.md.');
    }

    /**
     * The one GitHub button on the sources list, holding both steps.
     *
     * Registering the app and connecting an account are easy to mistake for
     * one another from two buttons side by side: the app is this registry's
     * identity on GitHub, registered once per instance, and connecting
     * installs it on an account, once per account. One menu puts them in
     * order. Connecting works either way — with no app yet it registers one
     * first — and the app's own entry is badged as the place to start.
     */
    public static function group(): ActionGroup
    {
        return ActionGroup::make([
            static::make()
                ->label('Connect an account'),
            static::registerApp(),
        ])
            ->label('Connect GitHub')
            ->icon(Heroicon::OutlinedChevronDown)
            ->iconPosition(IconPosition::After)
            ->color('primary')
            // Wide enough for "Register GitHub App" beside its badge, which the
            // default width cuts short.
            ->dropdownWidth(Width::ExtraSmall)
            ->button();
    }

    /**
     * The way to the app itself, labelled for what it does next: registering
     * one while there is none, and showing it once there is.
     */
    public static function registerApp(): Action
    {
        return Action::make('githubApp')
            ->label(fn (): string => static::isAvailable() ? 'GitHub App' : 'Register GitHub App')
            ->icon(Heroicon::OutlinedCommandLine)
            ->badge(fn (): ?string => static::isAvailable() ? null : 'Start here')
            ->url(GitHubAppResource::getUrl('index'))
            ->visible(fn (): bool => GitHubAppResource::canAccess());
    }

    public static function isAvailable(): bool
    {
        return app(GitHubApp::class)->isConfigured();
    }
}
