<?php

namespace App\Filament\Resources\Packages\Schemas;

use App\Models\Package;
use App\Models\Source;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * The flat package form used when editing.
 *
 * Each field is exposed on its own so that the create wizard can lay the same
 * inputs out over its steps without restating their rules.
 *
 * @see PackageWizard
 */
class PackageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::name(),
                self::repository(),
                self::source(),
                self::token(),
                self::latestVersion(),
                self::type(),
                self::description(),
            ]);
    }

    public static function name(): TextInput
    {
        return TextInput::make('name')
            ->required()
            ->maxLength(255)
            ->unique(ignoreRecord: true)
            ->placeholder('vendor/package')
            ->helperText('Overwritten by the composer.json name on sync.');
    }

    public static function repository(): TextInput
    {
        return TextInput::make('repository')
            ->label('Repository URL')
            ->required()
            ->url()
            ->maxLength(255)
            ->unique(ignoreRecord: true)
            ->placeholder('https://github.com/vendor/package');
    }

    public static function source(): Select
    {
        return Select::make('source_id')
            ->label('Source')
            ->options(fn (): array => Source::options())
            ->searchable()
            ->placeholder('Match automatically from the repository URL')
            ->helperText('The connected account this package authenticates through. Left empty, a source owning the repository URL is attached on save.');
    }

    public static function token(): TextInput
    {
        return TextInput::make('token')
            ->label('GitHub token')
            ->password()
            ->revealable()
            ->maxLength(255)
            // The stored token is never echoed back to the browser;
            // a blank input keeps it, a new value replaces it.
            ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
            ->dehydrated(fn (?string $state): bool => filled($state))
            ->placeholder(fn (?Package $record): string => $record?->token ? 'Token saved — enter a new one to replace it' : 'ghp_...')
            ->helperText('Only used for repositories no source covers — a connected source takes precedence over this. Falls back to GITHUB_TOKEN when both are empty.');
    }

    public static function latestVersion(): TextInput
    {
        return TextInput::make('latest_version')
            ->maxLength(255)
            ->placeholder('v1.0.0')
            ->helperText('Leave empty if the package has no release yet.');
    }

    public static function type(): TextInput
    {
        return TextInput::make('type')
            ->maxLength(255)
            // Suggests the types already in use without locking the
            // column to a fixed vocabulary.
            ->datalist(fn (): array => array_values(Package::types()));
    }

    public static function description(): Textarea
    {
        return Textarea::make('description')
            ->rows(4)
            ->columnSpanFull();
    }
}
