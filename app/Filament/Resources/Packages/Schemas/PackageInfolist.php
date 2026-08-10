<?php

namespace App\Filament\Resources\Packages\Schemas;

use App\Enums\SourceProvider;
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
                    ->url(fn (Package $record): ?string => $record->repository)
                    ->openUrlInNewTab()
                    ->color('primary')
                    ->placeholder('None — published by artifact upload'),
                TextEntry::make('subdirectory')
                    ->label('Subdirectory')
                    ->badge()
                    ->color('gray')
                    ->fontFamily(FontFamily::Mono)
                    // Shown only when there is one to show: the root is where
                    // almost every package lives, and a row saying so on every
                    // package would be a row nobody reads.
                    ->visible(fn (Package $record): bool => $record->hasSubdirectory())
                    ->helperText('This package is one of several published from the repository. Its dist archives carry this directory alone, re-rooted.'),
                TextEntry::make('latest_version')
                    ->label('Latest version')
                    ->badge()
                    ->placeholder('Unreleased'),
                TextEntry::make('type')
                    ->badge()
                    ->placeholder('-'),
                TextEntry::make('composerRepository.name')
                    ->label('Served in')
                    ->badge()
                    ->color('gray')
                    ->helperText(fn (Package $record): string => $record->composerRepository->public
                        ? 'Public repository — readable without a token.'
                        : 'Private repository — consumers need an access token.'),
                TextEntry::make('source.name')
                    ->label('Source')
                    ->badge()
                    ->color(fn (Package $record): string => $record->source?->isConnected() ? 'success' : 'warning')
                    ->url(fn (Package $record): ?string => $record->source
                        ? SourceResource::getUrl('view', ['record' => $record->source])
                        : null)
                    ->placeholder('No source — using this package\'s own credentials'),
                TextEntry::make('token')
                    ->label('Access token')
                    // Never render the secret itself.
                    ->formatStateUsing(fn (): string => 'Saved')
                    ->badge()
                    ->color('success')
                    // Only the environment fallback is GitHub's; the stored
                    // token itself serves whichever provider the repository
                    // URL resolves to.
                    ->placeholder(fn (Package $record): string => match (true) {
                        $record->source !== null => 'Not needed — authenticating through the source',
                        blank($record->repository) => 'Not needed — published by artifact upload',
                        $record->provider() === SourceProvider::Github => 'Using GITHUB_TOKEN fallback',
                        default => 'None — only a public repository can be read',
                    }),
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
