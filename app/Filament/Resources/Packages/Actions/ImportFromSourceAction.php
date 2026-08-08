<?php

namespace App\Filament\Resources\Packages\Actions;

use App\Jobs\SyncPackageJob;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Source;
use App\Services\GitHub\WebhookRegistrar;
use App\Sources\Project;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
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
            ->schema([
                Select::make('source_id')
                    ->label('Source')
                    ->options(fn (): array => Source::options())
                    ->required()
                    ->live()
                    ->helperText('The connected account whose projects to browse.'),
                Select::make('projects')
                    ->multiple()
                    ->required()
                    ->searchable()
                    ->options(fn (Get $get): array => self::projectOptions($get('source_id')))
                    ->getSearchResultsUsing(fn (string $search, Get $get): array => self::projectOptions($get('source_id'), $search))
                    ->helperText('Type to search what the source\'s credential can reach. Projects already onboarded are skipped.'),
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
     * @return array<string, string>
     */
    private static function projectOptions(mixed $sourceId, ?string $search = null): array
    {
        $source = Source::query()->find($sourceId);

        if (! $source instanceof Source) {
            return [];
        }

        try {
            $projects = $source->client()->projects($search);
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Could not list the source\'s projects')
                ->body($exception->getMessage())
                ->send();

            return [];
        }

        return collect($projects)
            ->mapWithKeys(fn (Project $project): array => [$project->webUrl => $project->fullName])
            ->all();
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
