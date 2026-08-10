<?php

namespace App\Filament\Resources\Packages\Schemas;

use App\Enums\SourceProvider;
use App\Models\Package;
use App\Models\Source;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * The two steps a package is created through: name the repository, then
 * confirm what it publishes as.
 *
 * The repository URL is the only thing an admin has to know. Moving on from it
 * reads the repository's composer.json — through the source that owns the URL,
 * or the credentials overridden on the first step — and the second step opens
 * with what that said, ready to be corrected.
 */
class PackageWizard
{
    /**
     * @return array<Step>
     */
    public static function steps(): array
    {
        return [
            Step::make('Repository')
                ->icon(Heroicon::OutlinedCodeBracketSquare)
                ->description('Where the package is published from')
                ->afterValidation(fn (Get $get, Set $set) => self::decipher($get, $set))
                ->schema([
                    PackageForm::repository()
                        ->autofocus()
                        // Drives the authentication summary below, which reads
                        // the owner out of whatever has been typed so far.
                        ->live(onBlur: true)
                        ->helperText('The package name and description are read from this repository\'s composer.json.'),
                    // On the first step rather than the second, because it is
                    // part of naming what is being imported: the manifest read
                    // on leaving this step is the one in this directory, and a
                    // monorepo has no useful composer.json anywhere else.
                    PackageForm::subdirectory()
                        ->helperText('Leave empty unless the repository publishes several packages. For a monorepo, the directory holding this package\'s composer.json — e.g. packages/widgets.'),
                    // Remembers which URL was read and with what credentials,
                    // so returning to this step does not re-read the repository
                    // and overwrite the edits made since — while coming back to
                    // fix the source or token after a failed read does.
                    Hidden::make('inspected_fingerprint')->dehydrated(false),
                    Section::make('Authentication')
                        ->description(fn (Get $get): string => self::authenticationSummary($get))
                        ->collapsible()
                        ->collapsed()
                        ->schema([
                            PackageForm::source(),
                            PackageForm::token(),
                        ]),
                ]),
            Step::make('Package')
                ->icon(Heroicon::OutlinedRectangleStack)
                ->description('How it is served to Composer')
                ->schema([
                    PackageForm::name()
                        ->helperText('Read from the repository\'s composer.json, and overwritten by it again on every sync.'),
                    PackageForm::composerRepository(),
                    PackageForm::description(),
                ]),
        ];
    }

    /**
     * Fill the second step in from the repository's composer.json.
     *
     * A repository that cannot be read is not a reason to stop: the name is
     * guessed from the URL instead and the admin is told why, since the fix —
     * a source or a token — is one step back.
     */
    private static function decipher(Get $get, Set $set): void
    {
        $repository = trim((string) $get('repository'));
        $fingerprint = self::fingerprint($get);

        if ($repository === '' || $fingerprint === $get('inspected_fingerprint')) {
            return;
        }

        $set('inspected_fingerprint', $fingerprint);

        $package = self::draft($get);
        $failure = null;

        try {
            $composerJson = $package->client()->composerJson(directory: $package->subdirectory);
        } catch (Throwable $exception) {
            $composerJson = null;
            $failure = $exception->getMessage();
        }

        // Lowercased here as well as on save, so the field shows the name the
        // registry will actually publish under rather than whatever case the
        // manifest happened to use. suggestedName() already lowercases.
        if (filled($name = $composerJson['name'] ?? $package->suggestedName())) {
            $set('name', mb_strtolower((string) $name));
        }

        // Only what was actually read is written over; a repository nobody
        // could reach says nothing about the package. The type and the latest
        // version are left to the first sync, which knows them for certain.
        if ($composerJson !== null) {
            $set('description', $composerJson['description'] ?? null);
        }

        if (isset($composerJson['name'])) {
            return;
        }

        Notification::make()
            ->warning()
            ->title('Could not read the repository\'s composer.json')
            ->body(($failure ?? 'No composer.json was found on the default branch, or the repository is private.')
                .' Go back to pick a source or enter a token if it needs credentials, otherwise fill the details in by hand.')
            ->send();
    }

    /**
     * Everything the read depends on, so changing the source or token after a
     * failed attempt counts as something new to inspect.
     */
    private static function fingerprint(Get $get): string
    {
        return json_encode([
            trim((string) $get('repository')),
            trim((string) $get('subdirectory')),
            $get('source_id'),
            $get('token') ?: null,
        ]);
    }

    /**
     * The unsaved package the first step describes, with the source that owns
     * its URL attached — so the read below is authenticated exactly the way
     * the eventual sync will be.
     */
    private static function draft(Get $get): Package
    {
        $package = new Package([
            'repository' => trim((string) $get('repository')),
            'source_id' => $get('source_id'),
            'token' => $get('token') ?: null,
        ]);

        // Normalized here as the model would on save, so the manifest is read
        // from the same place the sync will read it from — the field admits
        // "/packages/widgets/", the provider APIs do not.
        $package->subdirectory = (string) $get('subdirectory');
        rescue(fn () => $package->normalizeSubdirectory(), report: false);

        $package->linkSource();

        return $package;
    }

    /**
     * What the repository URL typed so far will authenticate as, which is what
     * makes the collapsed section worth opening — or not.
     */
    private static function authenticationSummary(Get $get): string
    {
        if (filled($get('source_id'))) {
            return 'Authenticating through the source chosen below.';
        }

        if (filled($get('token'))) {
            return 'Authenticating with the token entered below.';
        }

        $package = new Package(['repository' => trim((string) $get('repository'))]);
        $repositoryPath = $package->suggestedName();

        if ($repositoryPath === null) {
            return 'Matched from the repository URL. Override it here for a repository no source covers.';
        }

        $owner = strtok($repositoryPath, '/');

        if (Source::forRepositoryPath($repositoryPath) instanceof Source) {
            return "Matched to the source connected for \"{$owner}\".";
        }

        // The environment fallback is GitHub's alone, so a GitLab URL with
        // neither a source nor a token has nothing to authenticate with.
        return $package->provider() === SourceProvider::Github
            ? "No connected source covers \"{$owner}\", so GITHUB_TOKEN is used. Choose a source or enter a token if the repository is private."
            : "No connected source covers \"{$owner}\", and there is no environment fallback for {$package->provider()->getLabel()}. Choose a source or enter a token unless the repository is public.";
    }
}
