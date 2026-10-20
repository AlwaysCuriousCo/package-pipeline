<?php

namespace App\Filament\Resources\Packages\Actions;

use App\Filament\Resources\Packages\PackageResource;
use App\Jobs\SyncPackageJob;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Source;
use App\Services\GitHub\GitHubSourceClient;
use App\Services\GitHub\WebhookRegistrar;
use App\Services\SourceImportOptions;
use App\Sources\Project;
use App\Sources\ReadsComposerManifests;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component as SchemaComponent;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;
use Throwable;

/**
 * One-click onboarding: browse a source's projects, pick several, and each
 * becomes a package with its webhook arranged and its first sync queued —
 * the create wizard, multiplied.
 *
 * Adding a package later is the same dialog, so it starts where a new
 * repository usually gets stuck: a GitHub App installed on "only selected
 * repositories" cannot see one until it is granted on GitHub. That step
 * links to the installation's own settings in a new tab, so the dialog
 * stays open, and refreshing reads the grant back.
 */
class ImportFromSourceAction
{
    /**
     * @param  bool  $accessStep  offer the repository-access step first, where
     *                            the source's installation reaches only some
     *                            repositories
     * @param  bool  $openPackage  open the package once a single one has been
     *                             imported, where it can be installed once its
     *                             first sync lands
     */
    public static function make(bool $accessStep = true, bool $openPackage = true, bool $onboarding = false): Action
    {
        return Action::make('importFromSource')
            ->label($onboarding ? 'Choose packages' : 'Add packages')
            ->icon(Heroicon::OutlinedCloudArrowDown)
            ->color('gray')
            // Every project chosen becomes a package, so this is package
            // creation whatever screen the button sits on. The wizard builds
            // its own action and checks this itself; this one guards every
            // other mount point — the packages table, a source's page.
            ->authorize('create', Package::class)
            ->visible(fn (): bool => Source::query()->exists())
            ->modalHeading($onboarding ? 'Choose packages' : 'Add packages from a source')
            ->modalDescription('Each selected project becomes a package, wired up exactly as the create wizard would.')
            ->modalSubmitActionLabel('Import packages')
            // Opened with a source in mind — from "Connected acme", say, as
            // ?action=importFromSource&actionArguments[source]=… — or with the
            // only source there is, the dialog starts with that source's
            // likely packages already ticked.
            ->fillForm(function (array $arguments): array {
                $sourceId = self::sourceId($arguments);

                return self::defaultData($sourceId);
            })
            ->steps(fn (array $arguments): array => array_values(array_filter([
                $accessStep ? self::accessStep(self::sourceId($arguments)) : null,
                Step::make('Choose repositories')->schema(self::pickerFields()),
            ])))
            // With the access step left out, the one step left needs no
            // header saying so.
            ->modifyWizardUsing(fn (Wizard $wizard): Wizard => $wizard->hiddenHeader(fn (): bool => count($wizard->getChildSchema()->getComponents()) < 2))
            ->action(function (array $data, Component $livewire) use ($openPackage): void {
                [$created, $failed] = self::import($data);

                if ($openPackage && count($created) === 1 && ! $failed) {
                    $livewire->redirect(PackageResource::getUrl('view', ['record' => $created[0]]));
                }
            });
    }

    /** @return array{source_id: mixed, projects: list<string>, repository_id: int, create_webhook: bool} */
    public static function defaultData(mixed $sourceId): array
    {
        return [
            'source_id' => $sourceId,
            'projects' => self::likelyPackages($sourceId),
            'repository_id' => Repository::default()->id,
            'create_webhook' => true,
        ];
    }

    /**
     * The repository chosen in the picker, when anyone can read it without a
     * token.
     */
    public static function publicRepository(mixed $repositoryId): ?Repository
    {
        $repository = filled($repositoryId) ? Repository::query()->find($repositoryId) : null;

        return $repository?->public ? $repository : null;
    }

    public static function publicWarning(mixed $repositoryId): string
    {
        $name = self::publicRepository($repositoryId)->name ?? 'This repository';

        return "{$name} is public: anyone who can reach ".config('app.url').' can list and install the packages imported into it, without a token. Make it private to require one.';
    }

    public static function makePrivateAction(): Action
    {
        return Action::make('makeRepositoryPrivate')
            ->label('Make it private')
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('warning')
            ->visible(fn (Get $get): bool => ($repository = self::publicRepository($get('repository_id'))) !== null
                && (auth()->user()?->can('update', $repository) ?? false))
            ->action(function (Get $get): void {
                $repository = self::publicRepository($get('repository_id'));

                if ($repository === null) {
                    return;
                }

                abort_unless(auth()->user()?->can('update', $repository), 403);

                $repository->forceFill(['public' => false])->save();

                Notification::make()->success()->title("{$repository->name} is private")->body('Projects now need a token to install its packages.')->send();
            });
    }

    /** @return list<SchemaComponent> */
    public static function pickerFields(bool $inline = false): array
    {
        $projects = $inline
            ? CheckboxList::make('projects')
                ->options(fn (Get $get): array => self::inlineProjectOptions($get('source_id')))
                ->descriptions(fn (Get $get): array => self::inlineProjectDescriptions($get('source_id')))
                ->live()
                ->searchable()
            : Select::make('projects')
                ->multiple()
                ->searchable()
                ->options(fn (Get $get): array => self::projectOptions($get('source_id')))
                ->getSearchResultsUsing(fn (string $search, Get $get): array => self::projectOptions($get('source_id'), $search));

        return [
            Select::make('source_id')
                ->label('Source')
                ->options(fn (): array => Source::options())
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set, mixed $state) => $set('projects', self::likelyPackages($state)))
                ->helperText($inline ? 'A source is the connected GitHub account to browse. Choose the account whose repositories you want to serve.' : 'The connected account whose projects to browse.'),
            $projects
                ->label($inline ? 'Repositories' : 'Projects')
                ->required()
                ->helperText($inline ? 'Libraries with a root composer.json are pre-ticked; applications, forks and repositories already imported are not. Change the selection to match the packages you want.' : 'Repositories with a composer.json come first, and those that are packages rather than applications are already ticked. Type to search; projects already onboarded are skipped.'),
            ...($inline ? [Text::make(fn (Get $get): string => implode(' ', array_map(
                fn (string $name): string => "{$name} has no composer.json with a package name, so Composer can't install it. You can import it anyway.",
                self::selectedNonPackages($get('source_id'), $get('projects')),
            )))->color('warning')->visible(fn (Get $get): bool => self::selectedNonPackages($get('source_id'), $get('projects')) !== [])] : []),
            ...($inline ? [Text::make('No repositories were found for this source. Check that the GitHub App has access to the repositories you want, then return here. If nothing is pre-ticked, select a repository that contains a package rather than an application.')
                ->visible(fn (Get $get): bool => filled($get('source_id')) && self::inlineProjectOptions($get('source_id')) === [])] : []),
            ...($inline ? [Text::make('No likely PHP library packages were found. You can select a repository manually if it contains a package; applications and forks are not selected automatically.')
                ->visible(fn (Get $get): bool => filled($get('source_id')) && self::inlineProjectOptions($get('source_id')) !== [] && self::likelyPackages($get('source_id')) === [])] : []),
            Select::make('repository_id')
                ->label('Composer repository')
                ->helperText('Default is this registry’s starting Composer repository: it serves packages to Composer, and is separate from the repositories on GitHub. Choose another if these packages should be served separately.')
                ->options(fn (): array => Repository::query()->orderBy('name')->pluck('name', 'id')->all())
                ->default(fn (): int => Repository::default()->id)
                ->required()
                ->selectablePlaceholder(false)
                ->hidden(fn (): bool => $inline && Repository::query()->count() === 1)
                ->dehydratedWhenHidden(),
            ...($inline ? [Text::make('Default is this registry’s Composer repository: the address your projects install these packages from. It is separate from your repositories on GitHub.')
                ->visible(fn (): bool => Repository::query()->count() === 1)] : []),
            // Default is created public, and a first run imports into it
            // without asking, so the step says what that means before anything
            // private is served to anyone who can reach the registry.
            ...($inline ? [
                Text::make(fn (Get $get): string => self::publicWarning($get('repository_id')))
                    ->color('warning')
                    ->visible(fn (Get $get): bool => self::publicRepository($get('repository_id')) !== null),
                Actions::make([self::makePrivateAction()])
                    ->key('visibility')
                    ->visible(fn (Get $get): bool => self::publicRepository($get('repository_id')) !== null),
            ] : []),
            Toggle::make('create_webhook')
                ->label('Sync automatically on push')
                ->default(true)
                ->helperText($inline ? 'A sync copies new releases from GitHub into the registry. Keep this on to sync whenever code is pushed to GitHub; turn it off only if you would rather sync on a schedule or by hand.' : 'Arranges a webhook per project, unless an installed app\'s webhook already covers it.')
                ->hidden(fn (Get $get): bool => $inline && (Source::query()->find($get('source_id'))?->usesInstallation() ?? false))
                ->dehydratedWhenHidden(),
            ...($inline ? [Text::make('Automatic sync stays enabled: whenever code is pushed, the GitHub App tells the registry, which copies the new release (a sync). The import reports any setup still needed.')
                ->visible(fn (Get $get): bool => Source::query()->find($get('source_id'))?->usesInstallation() ?? false)] : []),
        ];
    }

    /** @return array<string, string> */
    private static function inlineProjectOptions(mixed $sourceId): array
    {
        if (! is_int($sourceId) && ! is_string($sourceId)) {
            return [];
        }

        return self::memo()->inline['source:'.$sourceId] ??= self::readInlineProjectOptions($sourceId);
    }

    /** @return array<string, string> */
    private static function inlineProjectDescriptions(mixed $sourceId): array
    {
        $source = Source::query()->find($sourceId);
        if (! $source instanceof Source) {
            return [];
        }

        $options = self::inlineProjectOptions($sourceId);
        if ($options === []) {
            return [];
        }
        $manifests = self::manifests($source);
        $imported = Package::query()->whereIn('repository', array_keys($options))->pluck('repository')->all();
        $descriptions = [];
        foreach ($options as $webUrl => $label) {
            $manifest = $manifests[$webUrl] ?? null;
            $descriptions[$webUrl] = match (true) {
                in_array($webUrl, $imported, true) => 'Already imported',
                $manifest === null || blank($manifest['name']) => 'Not a package: no composer.json with a name',
                $manifest['fork'] => 'Fork',
                $manifest['type'] === 'project' => 'Application (composer.json type is project)',
                default => 'Library: composer.json names '.$manifest['name'],
            };
        }

        return $descriptions;
    }

    /** @return list<string> */
    private static function selectedNonPackages(mixed $sourceId, mixed $selected): array
    {
        if (! is_int($sourceId) && ! is_string($sourceId)) {
            return [];
        }

        // The warning asking about these is asked twice per render — whether
        // to show it, and what it says — for the same selection.
        $key = 'source:'.$sourceId.':'.md5(serialize($selected));

        return self::memo()->nonPackages[$key] ??= self::readSelectedNonPackages($sourceId, $selected);
    }

    /** @return list<string> */
    private static function readSelectedNonPackages(mixed $sourceId, mixed $selected): array
    {
        $source = Source::query()->find($sourceId);
        if (! $source instanceof Source) {
            return [];
        }

        $options = self::inlineProjectOptions($sourceId);
        if ($options === []) {
            return [];
        }
        $manifests = self::manifests($source);

        return array_values(array_filter(
            array_intersect_key($options, array_flip(is_array($selected) ? $selected : [])),
            fn (string $label, string $webUrl): bool => blank($manifests[$webUrl]['name'] ?? null),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    private static function memo(): SourceImportOptions
    {
        $request = request();
        $memo = $request->attributes->get(SourceImportOptions::class);
        if (! $memo instanceof SourceImportOptions) {
            $memo = new SourceImportOptions;
            $request->attributes->set(SourceImportOptions::class, $memo);
        }

        return $memo;
    }

    /** @return array<string, string> */
    private static function readInlineProjectOptions(mixed $sourceId): array
    {
        $options = [];
        foreach (self::projectOptions($sourceId, inline: true) as $key => $value) {
            if (is_array($value)) {
                $options += $value;
            } else {
                $options[$key] = $value;
            }
        }

        return $options;
    }

    /**
     * The source the dialog opens on: the one it was opened for, or the only
     * one there is.
     *
     * @param  array<string, mixed>  $arguments
     */
    private static function sourceId(array $arguments): mixed
    {
        $sourceId = $arguments['source'] ?? null;

        if ($sourceId === null && Source::query()->count() === 1) {
            $sourceId = Source::query()->value('id');
        }

        return $sourceId;
    }

    /**
     * Granting a new repository to the source's GitHub App, for an
     * installation that reaches only the repositories chosen for it. An
     * installation on all of them, a token, or no single source in mind has
     * nothing to grant here, and gets no step.
     */
    private static function accessStep(mixed $sourceId): ?Step
    {
        $source = Source::query()->find($sourceId);

        if (! $source instanceof Source || ! $source->usesInstallation() || ($source->metadata['repository_selection'] ?? null) === 'all') {
            return null;
        }

        return Step::make('Repository access')->key('access')->schema([
            Text::make(fn (): string => sprintf(
                '%s shares only the repositories chosen for this registry\'s GitHub App%s. To add another, choose it on GitHub — the page opens in a new tab — then come back and refresh.',
                $source->account ?? $source->name,
                isset($source->metadata['repository_count']) ? " ({$source->metadata['repository_count']} so far)" : '',
            )),
            Actions::make([
                Action::make('grantAccess')
                    ->label('Grant access on GitHub')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (): ?string => $source->metadata['html_url'] ?? null, shouldOpenInNewTab: true)
                    // Sources connected before it was stored learn it on
                    // the first refresh.
                    ->visible(fn (): bool => filled($source->metadata['html_url'] ?? null)),
                Action::make('refreshAccess')
                    ->label('Done, refresh')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('gray')
                    ->action(function (Set $set) use ($source): void {
                        try {
                            $count = $source->verify();
                        } catch (Throwable $exception) {
                            Notification::make()->danger()->title('Could not read the installation')->body($exception->getMessage())->send();

                            return;
                        }

                        $client = $source->client();

                        if ($client instanceof GitHubSourceClient) {
                            $client->forgetComposerManifests();
                        }

                        $memo = self::memo();
                        unset($memo->inline['source:'.$source->id], $memo->likely['source:'.$source->id]);
                        $set('projects', self::likelyPackages($source->id));

                        Notification::make()->success()->title(trans_choice('{1} :count repository shared|[0,*] :count repositories shared', $count))->send();
                    }),
            ])->key('grant'),
        ]);
    }

    /**
     * The source's projects as select options, keyed by their web URL — which
     * is exactly what the package's repository column stores.
     *
     * Where the source can read manifests, the ones with a root composer.json
     * are grouped first under their Composer name, since those are the ones
     * anybody opening this dialog is looking for.
     *
     * @return array<string, string|array<string, string>>
     */
    private static function projectOptions(mixed $sourceId, ?string $search = null, bool $inline = false): array
    {
        $source = Source::query()->find($sourceId);

        if (! $source instanceof Source) {
            return [];
        }

        try {
            $projects = $source->client()->projects($search);
            $manifests = self::manifests($source);
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Could not list the source\'s projects')
                ->body($exception->getMessage())
                ->send();

            return [];
        }

        $options = collect($projects)->mapWithKeys(fn (Project $project): array => [
            $project->webUrl => ! $inline && filled($name = $manifests[$project->webUrl]['name'] ?? null)
                ? "{$name} ({$project->fullName})"
                : $project->fullName,
        ]);

        if ($manifests === []) {
            return $options->all();
        }

        [$packages, $others] = $options->partition(fn (string $label, string $webUrl): bool => isset($manifests[$webUrl]));

        return array_filter([
            'With a composer.json' => $packages->all(),
            'Other repositories' => $others->all(),
        ]);
    }

    /**
     * The projects to tick before anybody has: those with a root composer.json
     * that is not an application (`"type": "project"` is how Composer marks
     * one), that are not a fork of somebody else's package, and that are not
     * onboarded already.
     *
     * @return list<string>
     */
    private static function likelyPackages(mixed $sourceId): array
    {
        if (! is_int($sourceId) && ! is_string($sourceId)) {
            return [];
        }

        return self::memo()->likely['source:'.$sourceId] ??= self::readLikelyPackages($sourceId);
    }

    /** @return list<string> */
    private static function readLikelyPackages(mixed $sourceId): array
    {
        $source = Source::query()->find($sourceId);

        if (! $source instanceof Source) {
            return [];
        }

        try {
            $manifests = self::manifests($source);
        } catch (Throwable) {
            // The options say what went wrong; nothing ticked is the honest
            // default when the manifests could not be read.
            return [];
        }

        $candidates = array_keys(array_filter(
            $manifests,
            fn (array $manifest): bool => filled($manifest['name']) && $manifest['type'] !== 'project' && ! $manifest['fork'],
        ));

        $onboarded = Package::query()->whereIn('repository', $candidates)->pluck('repository')->all();

        return array_values(array_diff($candidates, $onboarded));
    }

    /**
     * @return array<string, array{name: ?string, type: ?string, fork: bool}>
     */
    private static function manifests(Source $source): array
    {
        $client = $source->client();

        return $client instanceof ReadsComposerManifests ? $client->composerManifests() : [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: list<Package>, 1: bool} the packages created, and whether
     *                                          any project failed
     */
    public static function import(array $data): array
    {
        $source = Source::query()->findOrFail($data['source_id']);
        $repository = Repository::query()->findOrFail($data['repository_id']);

        // Importing adds packages to the destination repository, which is a
        // change to it wherever the dialog was opened from — including the
        // wizard, which checks the source and package creation itself but
        // left the destination to the picker.
        abort_unless(auth()->user()?->can('update', $repository) ?? false, 403);

        $registrar = app(WebhookRegistrar::class);

        $created = [];
        $skipped = 0;
        $failures = [];

        foreach ($data['projects'] as $webUrl) {
            try {
                $package = Package::query()->firstOrCreate(
                    ['repository_id' => $repository->id, 'repository' => $webUrl],
                    [
                        'source_id' => $source->id,
                        'name' => (new Package(['repository' => $webUrl]))->suggestedName() ?? $webUrl,
                    ],
                );
            } catch (Throwable $exception) {
                // One refused project — a name collision, say — must not
                // abort the rest of the import.
                $failures[] = "{$webUrl}: {$exception->getMessage()}";

                continue;
            }

            if (! $package->wasRecentlyCreated) {
                $skipped++;

                continue;
            }

            $created[] = $package;

            if ($data['create_webhook']) {
                $registrar->register($package);

                if ($reason = $registrar->unmetRequirement($package)) {
                    $failures[] = "{$package->name}: {$reason}";
                }
            }

            SyncPackageJob::dispatch($package);
        }

        if ($created !== [] || $skipped > 0) {
            Notification::make()
                ->success()
                ->title(sprintf(
                    'Imported %d package%s%s',
                    count($created),
                    count($created) === 1 ? '' : 's',
                    $skipped > 0 ? " ({$skipped} already onboarded)" : '',
                ))
                ->body($created === [] ? null : implode(', ', array_map(fn (Package $package): string => $package->name, $created)).' — first syncs are running in the background.')
                ->send();
        }

        if ($failures !== []) {
            Notification::make()
                ->warning()
                ->title('Some projects need attention')
                ->body(implode("\n", $failures))
                ->persistent()
                ->send();
        }

        return [$created, $failures !== []];
    }
}
