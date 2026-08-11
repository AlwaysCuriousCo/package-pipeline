<?php

namespace App\Filament\Resources\Packages\RelationManagers;

use App\Filament\Resources\Packages\Actions\DownloadArchiveAction;
use App\Models\Package;
use App\Models\PackageVersion;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    /**
     * Deleting one stuck version is a repair, not editing: the row is created
     * by the sync and nothing here writes another. Filament makes relation
     * managers read-only on a resource's view page, which would leave the
     * repair reachable only from the edit page — where nothing else about
     * versions lives.
     *
     * Whether the delete is then *allowed* is still PackagePolicy's decision,
     * and which packages this page opens for is still the resource's.
     */
    public function isReadOnly(): bool
    {
        return false;
    }

    /**
     * What the package publishes for this version, read back out of the
     * composer.json the sync stored.
     */
    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('version')->badge(),
            TextEntry::make('reference')
                ->label('Commit')
                ->fontFamily(FontFamily::Mono)
                ->copyable(),
            TextEntry::make('released_at')
                ->label('Released')
                ->dateTime()
                ->placeholder('Unknown'),
            TextEntry::make('license')
                ->state(fn (PackageVersion $record): array => $record->licenses())
                ->badge()
                ->placeholder('Unlicensed'),
            TextEntry::make('authors')
                ->state(fn (PackageVersion $record): array => $record->authorLines())
                ->listWithLineBreaks()
                ->placeholder('None declared'),
            TextEntry::make('total_downloads')
                ->label('Downloads')
                ->numeric(),
            Section::make('Requirements')
                ->description('What Composer installs alongside this version.')
                ->icon(Heroicon::OutlinedSquares2x2)
                ->columnSpanFull()
                ->schema([
                    TextEntry::make('require')
                        ->label('require')
                        ->state(fn (PackageVersion $record): array => $record->requirements())
                        ->listWithLineBreaks()
                        ->fontFamily(FontFamily::Mono)
                        ->placeholder('Nothing — this version depends on nothing.'),
                    TextEntry::make('require_dev')
                        ->label('require-dev')
                        ->state(fn (PackageVersion $record): array => $record->requirements('require-dev'))
                        ->listWithLineBreaks()
                        ->fontFamily(FontFamily::Mono)
                        // Only installed for the package's own development,
                        // never for a project that requires it.
                        ->placeholder('None declared.'),
                ]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('version')
            ->defaultSort('version', 'desc')
            ->columns([
                TextColumn::make('version')
                    ->badge()
                    ->searchable()
                    // A lexical sort puts 1.9.0 above 1.10.0; the `order`
                    // column exists to spell the semantic one, and is what
                    // the served /p2 documents already sort by. Sorting the
                    // column rather than the table means the default sort
                    // below and a click on the header agree.
                    ->sortable(query: self::sortByVersion(...)),
                IconColumn::make('is_dev')
                    ->label('Dev')
                    ->icon(fn (PackageVersion $record): Heroicon => $record->is_dev
                        ? Heroicon::Beaker
                        : Heroicon::RocketLaunch)
                    ->color(fn (PackageVersion $record): string => match (true) {
                        $record->is_dev => 'info',
                        $this->isLatestRelease($record) => 'success',
                        default => 'gray',
                    })
                    ->tooltip(fn (PackageVersion $record): string => match (true) {
                        $record->is_dev => 'Development version',
                        $this->isLatestRelease($record) => 'Latest release',
                        default => 'Past release',
                    }),
                TextColumn::make('reference')
                    ->label('Commit')
                    ->limit(12)
                    ->copyable(),
                TextColumn::make('total_downloads')
                    ->label('Downloads')
                    // The per-version counter the download listener keeps and
                    // `downloads:recalculate` rebuilds, rather than a count
                    // over the downloads table per row.
                    ->numeric()
                    ->sortable(),
                TextColumn::make('released_at')
                    ->label('Released')
                    ->dateTime()
                    ->description(fn (PackageVersion $record): ?string => $record->released_at?->diffForHumans())
                    ->placeholder('Unknown')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Last synced')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_dev')
                    ->label('Dev versions'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->modalHeading(fn (PackageVersion $record): string => "Version {$record->version}")
                    // The same download offered again from inside the detail
                    // modal, so an operator who opened a version to check what
                    // it declares does not have to close it again to take the
                    // artifact that declaration describes.
                    ->extraModalFooterActions([
                        DownloadArchiveAction::make('downloadArchiveFromDetail'),
                    ]),
                DownloadArchiveAction::make(),
                DeleteAction::make()
                    // PackageVersion has no policy of its own — the rows are
                    // the package's contents, so deleting one is an edit to
                    // the package and is gated as one. Without this the
                    // ungated default would let anyone who can open a package
                    // unpublish its versions.
                    ->authorize(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) ?? false)
                    ->requiresConfirmation()
                    ->modalHeading('Delete this version')
                    // The one honest warning: this is a repair for a row that
                    // is wrong, not a way to unpublish a release.
                    ->modalDescription('It stops being served immediately, and the nightly archive sweep reclaims its stored zip. The next sync re-imports it unless the tag or branch is gone from the source — so this repairs a bad import, it does not retract a release.')
                    ->successNotificationTitle('Version deleted'),
            ]);
    }

    /**
     * @param  Builder<PackageVersion>  $query
     * @return Builder<PackageVersion>
     */
    private static function sortByVersion(Builder $query, string $direction): Builder
    {
        return $query->orderedByVersion($direction);
    }

    /**
     * Whether this row is the release the package currently resolves to.
     *
     * The sync stores that choice on the package as `latest_version`, so the
     * table reads it rather than re-deriving an ordering the column sort can
     * only approximate.
     */
    private function isLatestRelease(PackageVersion $record): bool
    {
        /** @var Package $package */
        $package = $this->getOwnerRecord();

        $latest = $package->latest_version;

        return $latest !== null && $record->version === $latest;
    }
}
