<?php

namespace App\Filament\Resources\Sources\Tables;

use App\Enums\SourceProvider;
use App\Filament\Resources\Sources\Actions\ConnectGitHubAction;
use App\Filament\Resources\Sources\Actions\ConnectSourceAction;
use App\Filament\Resources\Sources\Actions\TestSourceAction;
use App\Models\Source;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SourcesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->emptyStateIcon(Heroicon::OutlinedCloud)
            ->emptyStateHeading('No sources connected')
            ->emptyStateDescription('Connect a GitHub account and every package under it authenticates through this source — no token per repository.')
            ->emptyStateActions([
                ConnectGitHubAction::make(),
            ])
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('provider')
                    ->badge()
                    ->sortable(),
                TextColumn::make('account')
                    ->label('Organisation or user')
                    ->searchable()
                    ->sortable()
                    ->url(fn (Source $record): ?string => $record->account
                        ? "https://{$record->provider->host()}/{$record->account}"
                        : null)
                    ->openUrlInNewTab()
                    ->color('primary')
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->badge()
                    ->state(fn (Source $record): string => match (true) {
                        $record->connection_error !== null => 'Failing',
                        $record->isConnected() => 'Connected',
                        $record->usesInstallation() || filled($record->token) => 'Untested',
                        default => 'Not connected',
                    })
                    ->color(fn (Source $record): string => match (true) {
                        $record->connection_error !== null => 'danger',
                        $record->isConnected() => 'success',
                        default => 'gray',
                    })
                    ->tooltip(fn (Source $record): ?string => $record->connection_error),
                TextColumn::make('packages_count')
                    ->label('Packages')
                    ->counts('packages')
                    ->sortable(),
                TextColumn::make('connected_at')
                    ->label('Connected')
                    ->since()
                    ->sortable()
                    ->placeholder('Never')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('base_url')
                    ->label('API base URL')
                    ->placeholder('github.com')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('provider')
                    ->options(SourceProvider::class),
                Filter::make('disconnected')
                    ->label('Needs attention')
                    ->query(fn (Builder $query): Builder => $query->whereNull('connected_at')),
            ])
            ->recordActions([
                ConnectSourceAction::make(),
                TestSourceAction::make(),
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
