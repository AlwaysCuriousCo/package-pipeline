<?php

namespace App\Filament\Resources\Packages\Schemas;

use App\Enums\WebhookCoverage;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Source;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

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
                self::composerRepository(),
                self::source(),
                self::token(),
                self::latestVersion(),
                self::type(),
                self::webhookEnabled(),
                self::abandoned(),
                self::replacementPackage(),
                self::description(),
            ]);
    }

    /**
     * Marks the package as one nobody should be depending on any more.
     *
     * Composer carries this through to the consumer: `composer audit` reports
     * abandoned packages, and since 2.9 a project can configure that to fail
     * the build outright. It is the only way this registry can tell a
     * developer to stop reaching for something without deleting it out from
     * under the projects already using it.
     */
    public static function abandoned(): Toggle
    {
        return Toggle::make('abandoned')
            ->label('Abandoned')
            ->live()
            ->helperText('Warns anyone who installs or audits this package that it is no longer maintained. Existing versions keep resolving.');
    }

    /**
     * What to use instead, which Composer names in the warning it prints.
     */
    public static function replacementPackage(): TextInput
    {
        return TextInput::make('replacement_package')
            ->label('Use instead')
            ->maxLength(255)
            ->visible(fn (Get $get): bool => (bool) $get('abandoned'))
            ->placeholder('vendor/package')
            // Deliberately unvalidated against the registry's own packages: the
            // replacement is often somewhere else entirely — a public package
            // on packagist.org, or one not imported here yet.
            ->helperText('Optional. Any package name, including one this registry does not serve. Left empty, consumers are told only that the package is abandoned.');
    }

    /**
     * The Composer repository the package is served from. Names and VCS URLs
     * are unique per repository, so this select is what the two unique rules
     * below scope themselves by.
     */
    public static function composerRepository(): Select
    {
        return Select::make('repository_id')
            ->label('Composer repository')
            ->relationship('composerRepository', 'name')
            ->default(fn (): int => Repository::default()->id)
            ->required()
            ->selectablePlaceholder(false)
            ->helperText('Which of this registry\'s repositories serves the package.');
    }

    /**
     * Scope a unique rule to the repository chosen in the form, since both
     * package names and VCS URLs are unique per Composer repository rather
     * than globally.
     */
    private static function uniquePerRepository(Unique $rule, Get $get): Unique
    {
        return $rule->where('repository_id', $get('repository_id') ?? Repository::default()->id);
    }

    /**
     * Whether pushes to the repository sync this package.
     *
     * Saving this on decides how the package is reached — a hook is created on
     * the repository unless the GitHub App's webhook already covers it —
     * and saving it off takes that hook back down. Deliveries that arrive
     * anyway, through the app's account-wide webhook, are ignored for this
     * package, so the switch means the same thing either way.
     */
    public static function webhookEnabled(): Toggle
    {
        return Toggle::make('webhook_enabled')
            ->label('Sync automatically on push')
            ->default(true)
            ->helperText(fn (?Package $record): string => match (true) {
                ! $record instanceof Package => 'A webhook is set up on the repository when the package is created, unless the GitHub App\'s webhook already covers it.',
                $record->webhook_enabled => 'On. '.($record->webhookCoverage() === WebhookCoverage::Application
                    ? 'Delivered through the GitHub App\'s webhook; turning this off stops this package syncing on push, and leaves the app\'s webhook alone.'
                    : 'Turning this off removes the webhook from the repository.'),
                default => 'Off — this package only syncs when asked. Turning it on sets the webhook up again.',
            });
    }

    public static function name(): TextInput
    {
        return TextInput::make('name')
            ->required()
            ->maxLength(255)
            ->unique(
                ignoreRecord: true,
                modifyRuleUsing: fn (Unique $rule, Get $get): Unique => self::uniquePerRepository($rule, $get),
            )
            ->placeholder('vendor/package')
            ->helperText('Overwritten by the composer.json name on sync.');
    }

    public static function repository(): TextInput
    {
        return TextInput::make('repository')
            ->label('Repository URL')
            // Required for a synced package; a package published by artifact
            // upload legitimately has none. The create wizard, whose whole
            // point is deciphering a repository, re-requires it.
            ->required(fn (?Package $record): bool => $record === null || filled($record->repository))
            ->url()
            ->maxLength(255)
            ->unique(
                ignoreRecord: true,
                modifyRuleUsing: fn (Unique $rule, Get $get): Unique => self::uniquePerRepository($rule, $get),
            )
            ->placeholder('https://github.com/vendor/package')
            ->helperText(fn (?Package $record): ?string => $record !== null && blank($record->repository)
                ? 'This package is published by artifact upload; setting a repository URL turns syncing on.'
                : null);
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
