<?php

namespace App\Filament\Resources\Packages\Schemas;

use App\Enums\WebhookCoverage;
use App\Filament\Resources\Sources\SourceResource;
use App\Models\Package;
use App\Services\GitHub\WebhookRegistrar;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;

class PackageInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name'),
                TextEntry::make('repository')
                    ->label('Repository URL')
                    ->url(fn (Package $record): string => $record->repository)
                    ->openUrlInNewTab()
                    ->color('primary'),
                TextEntry::make('latest_version')
                    ->label('Latest version')
                    ->badge()
                    ->placeholder('Unreleased'),
                TextEntry::make('type')
                    ->badge()
                    ->placeholder('-'),
                TextEntry::make('source.name')
                    ->label('Source')
                    ->badge()
                    ->color(fn (Package $record): string => $record->source?->isConnected() ? 'success' : 'warning')
                    ->url(fn (Package $record): ?string => $record->source
                        ? SourceResource::getUrl('view', ['record' => $record->source])
                        : null)
                    ->placeholder('No source — using this package\'s own credentials'),
                TextEntry::make('token')
                    ->label('GitHub token')
                    // Never render the secret itself.
                    ->formatStateUsing(fn (): string => 'Saved')
                    ->badge()
                    ->color('success')
                    ->placeholder(fn (Package $record): string => $record->source
                        ? 'Not needed — authenticating through the source'
                        : 'Using GITHUB_TOKEN fallback'),
                TextEntry::make('last_synced_at')
                    ->label('Last synced')
                    ->since()
                    ->placeholder('Never'),
                TextEntry::make('sync_error')
                    ->label('Sync error')
                    ->color('danger')
                    ->placeholder('None')
                    ->columnSpanFull(),
                TextEntry::make('webhook_coverage')
                    ->label('Auto-sync')
                    ->badge()
                    // Coverage is worked out from the source and the stored
                    // hook rather than held in a column, so the entry is given
                    // its state instead of reading one.
                    ->state(fn (Package $record): WebhookCoverage => $record->webhookCoverage())
                    ->helperText(fn (Package $record): ?string => app(WebhookRegistrar::class)->unmetRequirement($record)),
                TextEntry::make('webhook_received_at')
                    ->label('Last delivery')
                    ->since()
                    ->placeholder(fn (Package $record): string => $record->webhookCoverage()->isActive()
                        ? 'Nothing pushed yet'
                        : 'Never'),
                TextEntry::make('description')
                    ->placeholder('-')
                    ->columnSpanFull(),
                Section::make('Install')
                    ->description('Run these in the consuming project. Click a command to copy it.')
                    ->icon(Heroicon::OutlinedCommandLine)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('install_repository')
                            ->label('1. Register this Composer repository (once per project)')
                            ->state(fn (Package $record): string => $record->installCommands()['repository'])
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->copyMessage('Command copied'),
                        TextEntry::make('install_require')
                            ->label('2. Require the package')
                            ->state(fn (Package $record): string => $record->installCommands()['require'])
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->copyMessage('Command copied'),
                    ]),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}
