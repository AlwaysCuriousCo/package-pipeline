<?php

namespace App\Filament\Resources\Packages\RelationManagers;

use App\Models\PackageVersion;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('version')
            ->defaultSort('version', 'desc')
            ->columns([
                TextColumn::make('version')
                    ->badge()
                    ->searchable()
                    ->sortable(),
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
            ]);
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
        $latest = $this->getOwnerRecord()->latest_version;

        return $latest !== null && $record->version === $latest;
    }
}
