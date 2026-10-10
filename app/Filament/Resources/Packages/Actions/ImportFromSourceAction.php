<?php

namespace App\Filament\Resources\Packages\Actions;

use App\Jobs\SyncPackageJob;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Source;
use App\Services\GitHub\WebhookRegistrar;
use App\Sources\Project;
use App\Sources\ReadsComposerManifests;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * One-click onboarding: browse a source's projects, pick several, and each
 * becomes a package with its webhook arranged and its first sync queued —
 * the create wizard, multiplied.
 */
class ImportFromSourceAction
{
    public static function make(): Action
    {
        return Action::make('importFromSource')
            ->label('Import from source')
            ->icon(Heroicon::OutlinedCloudArrowDown)
            ->color('gray')
            ->visible(fn (): bool => Source::query()->exists())
            ->modalHeading('Import packages from a source')
            ->modalDescription('Each selected project becomes a package, wired up exactly as the create wizard would.')
            ->modalSubmitActionLabel('Import')
            // Opened with a source in mind — from "Connected acme", say, as
            // ?action=importFromSource&actionArguments[source]=… — or with the
            // only source there is, the dialog starts with that source's
            // likely packages already ticked.
            ->fillForm(function (array $arguments): array {
                $sourceId = $arguments['source'] ?? null;

                if ($sourceId === null && Source::query()->count() === 1) {
                    $sourceId = Source::query()->value('id');
                }

                return [
                    'source_id' => $sourceId,
                    'projects' => self::likelyPackages($sourceId),
                    'repository_id' => Repository::default()->id,
                    'create_webhook' => true,
                ];
            })
            ->schema([
                Select::make('source_id')
                    ->label('Source')
                    ->options(fn (): array => Source::options())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set, mixed $state) => $set('projects', self::likelyPackages($state)))
                    ->helperText('The connected account whose projects to browse.'),
                Select::make('projects')
                    ->multiple()
                    ->required()
                    ->searchable()
                    ->options(fn (Get $get): array => self::projectOptions($get('source_id')))
                    ->getSearchResultsUsing(fn (string $search, Get $get): array => self::projectOptions($get('source_id'), $search))
                    ->helperText('Repositories with a composer.json come first, and those that are packages rather than applications are already ticked. Type to search; projects already onboarded are skipped.'),
                Select::make('repository_id')
                    ->label('Composer repository')
                    ->options(fn (): array => Repository::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->default(fn (): int => Repository::default()->id)
                    ->required()
                    ->selectablePlaceholder(false),
                Toggle::make('create_webhook')
                    ->label('Sync automatically on push')
                    ->default(true)
                    ->helperText('Arranges a webhook per project, unless an installed app\'s webhook already covers it.'),
            ])
            ->action(fn (array $data) => self::import($data));
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
    private static function projectOptions(mixed $sourceId, ?string $search = null): array
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
            $project->webUrl => filled($name = $manifests[$project->webUrl]['name'] ?? null)
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
     */
    private static function import(array $data): void
    {
        $source = Source::query()->findOrFail($data['source_id']);
        $repository = Repository::query()->findOrFail($data['repository_id']);
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

            $created[] = $package->name;

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
                ->body($created === [] ? null : implode(', ', $created).' — first syncs are running in the background.')
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
    }
}
