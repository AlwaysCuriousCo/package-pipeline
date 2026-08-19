<?php

namespace App\Filament\Resources\Repositories\RelationManagers;

use App\Filament\Resources\Packages\PackageResource;
use App\Models\Package;
use App\Models\Repository;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * What this repository actually serves.
 *
 * The form above is all configuration — paths, reservations, upstreams — and
 * none of it says what a consumer pointed at the mount would find.
 *
 * Two kinds of row, and the difference matters: a package that *lives* here,
 * which this screen links out to rather than editing in place, and one served
 * here on top of living somewhere else. The second kind can be added and
 * removed from this side, because that is the decision this screen is about —
 * what this mount answers for. Moving a package is still the package's own
 * form, since it changes the URL Composer resolves it at.
 */
class PackagesRelationManager extends RelationManager
{
    protected static string $relationship = 'packages';

    protected static ?string $title = 'Packages';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('name')
            ->emptyStateHeading('No packages served here yet')
            ->emptyStateDescription('Packages added to this repository are served from its mount path.')
            ->recordUrl(fn (Package $record): string => PackageResource::getUrl('view', ['record' => $record]))
            ->headerActions([
                Action::make('createPackage')
                    ->label('New package')
                    ->icon(Heroicon::OutlinedPlus)
                    // Creation is a wizard of its own, so this links out to it
                    // rather than opening a modal.
                    ->url(fn (): string => PackageResource::getUrl('create'))
                    ->visible(fn (): bool => PackageResource::canCreate()),
                $this->serveExisting(),
            ])
            ->recordActions([
                $this->stopServing(),
            ])
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    // Which of the two kinds of row this is. Said on the name
                    // rather than in a column of its own, because for most
                    // repositories every row is the same kind and a column
                    // repeating "Lives here" is a column nobody reads.
                    ->description(fn (Package $record): ?string => $record->repository_id === $this->getOwnerRecord()->getKey()
                        ? null
                        : 'Served here; lives in '.$record->composerRepository->name),
                TextColumn::make('latest_version')
                    ->label('Latest version')
                    ->badge()
                    ->placeholder('Unreleased'),
                TextColumn::make('source.name')
                    ->label('Source')
                    ->placeholder('None')
                    ->sortable(),
                TextColumn::make('last_synced_at')
                    ->label('Last synced')
                    ->since()
                    ->sortable()
                    ->placeholder('Never')
                    ->color(fn (Package $record): ?string => $record->sync_error ? 'danger' : null)
                    ->tooltip(fn (Package $record): ?string => $record->sync_error),
            ]);
    }

    /**
     * Add a package that lives elsewhere to this repository's mount.
     *
     * Not Filament's AttachAction, which would write the pivot row itself: a
     * serving row carries the package's name for the per-repository unique
     * index, and the two refusals — a name this repository already answers
     * for, a vendor another repository has reserved — are decisions worth
     * stating rather than unique-index errors.
     */
    private function serveExisting(): Action
    {
        return Action::make('serveExisting')
            ->label('Serve an existing package')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            ->modalHeading('Serve an existing package here')
            ->modalDescription('The same package, answering under this repository\'s mount as well as its own — one row, one sync, one set of archives.')
            ->modalSubmitActionLabel('Serve here')
            // The permission the package form asks for, since this is the
            // same decision made from the other side of it.
            ->visible(fn (): bool => Gate::allows('Update:Package'))
            ->schema([
                Select::make('packages')
                    ->label('Packages')
                    ->multiple()
                    ->required()
                    ->searchable()
                    ->options(fn (): array => $this->servableOptions())
                    ->getSearchResultsUsing(fn (string $search): array => $this->servableOptions($search))
                    ->helperText('Packages this repository does not serve yet. A name it already answers for cannot be added twice.'),
            ])
            ->action(function (array $data): void {
                $repository = $this->repository();
                $served = [];
                $refused = [];

                foreach (Package::query()->whereKey($data['packages'])->get() as $package) {
                    try {
                        $package->serveFrom([$repository]);
                        $served[] = $package->name;
                    } catch (Throwable $exception) {
                        // One refusal must not take the rest of the selection
                        // with it, and it has to be said: silently serving
                        // three of four is how a consumer's install fails.
                        $refused[] = "{$package->name}: {$exception->getMessage()}";
                    }
                }

                if ($served !== []) {
                    Notification::make()
                        ->success()
                        ->title(count($served).' package(s) now served here')
                        ->body(implode(', ', $served))
                        ->send();
                }

                if ($refused !== []) {
                    Notification::make()
                        ->danger()
                        ->title('Some packages could not be served here')
                        ->body(implode(' — ', $refused))
                        ->persistent()
                        ->send();
                }
            });
    }

    /**
     * Stop serving a package that lives somewhere else.
     *
     * Only ever offered for those: a package's home repository is where it
     * lives, and detaching it from that is a move rather than a removal.
     */
    private function stopServing(): Action
    {
        return Action::make('stopServing')
            ->label('Stop serving')
            ->icon(Heroicon::OutlinedMinusCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Stop serving this package here')
            ->modalDescription('Consumers resolving it through this mount will stop finding it. The package itself, and every other repository serving it, is untouched.')
            ->visible(fn (Package $record): bool => $record->repository_id !== $this->getOwnerRecord()->getKey()
                && Gate::allows('update', $record))
            ->action(function (Package $record): void {
                $record->stopServingFrom([$this->repository()]);

                Notification::make()
                    ->success()
                    ->title("{$record->name} is no longer served here")
                    ->send();
            });
    }

    /**
     * The repository this screen hangs off, typed. `getOwnerRecord()` answers
     * `Model`, and everything below wants the two methods only a Repository
     * has.
     */
    private function repository(): Repository
    {
        return Repository::query()->findOrFail($this->getOwnerRecord()->getKey());
    }

    /**
     * The packages this repository could serve and does not, as options.
     *
     * Bounded rather than exhaustive: a registry is allowed to hold more
     * packages than a select can render, which is what the search is for.
     *
     * @return array<int, string>
     */
    private function servableOptions(?string $search = null): array
    {
        $repository = $this->repository();

        return Package::query()
            ->whereDoesntHave('repositories', fn (Builder $query) => $query->whereKey($repository->getKey()))
            ->when($search !== null, fn (Builder $query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->limit(50)
            ->pluck('name', 'id')
            ->all();
    }
}
