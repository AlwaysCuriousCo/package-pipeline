<?php

namespace App\Filament\Resources\Sources\Schemas;

use App\Enums\SourceProvider;
use App\Models\Source;
use App\Services\GitHub\GitHubApp;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->placeholder('Acme engineering')
                    ->helperText('A label for this connection; only shown in the admin.'),
                Select::make('provider')
                    ->options(SourceProvider::class)
                    ->default(SourceProvider::Github)
                    ->required()
                    ->selectablePlaceholder(false),
                TextInput::make('account')
                    ->label('Organisation or user')
                    ->maxLength(255)
                    ->placeholder('acme')
                    // Required for a token-based source, since there is no
                    // installation to read the account off. Connecting fills
                    // it in instead.
                    ->required(fn (?Source $record): bool => ! $record?->usesInstallation())
                    ->helperText('Packages whose repository sits under this owner authenticate through this source.'),
                TextInput::make('base_url')
                    ->label('API base URL')
                    ->url()
                    ->maxLength(255)
                    ->placeholder('https://api.github.com')
                    ->helperText('Only needed for GitHub Enterprise; leave empty for github.com.'),
                TextInput::make('token')
                    ->label('Access token')
                    ->password()
                    ->revealable()
                    ->maxLength(255)
                    // The stored token is never echoed back to the browser;
                    // a blank input keeps it, a new value replaces it.
                    ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->placeholder(fn (?Source $record): string => $record?->token ? 'Token saved — enter a new one to replace it' : 'github_pat_...')
                    ->helperText(fn (): string => app(GitHubApp::class)->isConfigured()
                        ? 'Optional. Leave empty and use "Connect" instead — an installed GitHub App issues short-lived tokens scoped to the repositories you pick.'
                        : 'No GitHub App is configured on this instance, so a source needs a token here. See docs/github-app.md to set the app up instead.')
                    ->columnSpanFull(),
            ]);
    }
}
