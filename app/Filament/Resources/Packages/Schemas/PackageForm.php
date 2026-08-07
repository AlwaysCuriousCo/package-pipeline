<?php

namespace App\Filament\Resources\Packages\Schemas;

use App\Models\Package;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PackageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->placeholder('vendor/package')
                    ->helperText('Overwritten by the composer.json name on sync.'),
                TextInput::make('repository')
                    ->label('Repository URL')
                    ->required()
                    ->url()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->placeholder('https://github.com/vendor/package'),
                TextInput::make('token')
                    ->label('GitHub token')
                    ->password()
                    ->revealable()
                    ->maxLength(255)
                    // The stored token is never echoed back to the browser;
                    // a blank input keeps it, a new value replaces it.
                    ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->placeholder(fn (?Package $record): string => $record?->token ? 'Token saved — enter a new one to replace it' : 'ghp_...')
                    ->helperText('Personal access token with read access to the repository. Falls back to GITHUB_TOKEN when empty.'),
                TextInput::make('latest_version')
                    ->maxLength(255)
                    ->placeholder('v1.0.0')
                    ->helperText('Leave empty if the package has no release yet.'),
                TextInput::make('type')
                    ->maxLength(255)
                    // Suggests the types already in use without locking the
                    // column to a fixed vocabulary.
                    ->datalist(fn (): array => array_values(Package::types())),
                Textarea::make('description')
                    ->rows(4)
                    ->columnSpanFull(),
            ]);
    }
}
