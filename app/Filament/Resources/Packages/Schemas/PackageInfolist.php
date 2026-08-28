<?php

namespace App\Filament\Resources\Packages\Schemas;

use App\Enums\SourceProvider;
use App\Enums\TokenAbility;
use App\Enums\WebhookCoverage;
use App\Filament\Pages\ApiTokens;
use App\Filament\Resources\DeployTokens\DeployTokenResource;
use App\Filament\Resources\Packages\Pages\ViewPackage;
use App\Filament\Resources\Sources\SourceResource;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Token;
use App\Services\GitHub\WebhookRegistrar;
use Filament\Actions\Action;
use Filament\Actions\SelectAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

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
                    ->label('Lives in')
                    ->badge()
                    ->color('gray')
                    ->helperText(fn (Package $record): string => $record->composerRepository->public
                        ? 'Public repository — readable without a token.'
                        : 'Private repository — consumers need an access token.'),
                TextEntry::make('serving_repositories')
                    ->label('Also served from')
                    ->badge()
                    ->color('gray')
                    // The same package under other mounts, each with its own
                    // access rules — which is why the repository it lives in
                    // says nothing about how readable it is over there.
                    ->state(fn (Package $record): array => Repository::query()
                        ->whereKey(array_diff($record->servingRepositoryIds(), [(int) $record->repository_id]))
                        ->orderBy('name')
                        ->pluck('name')
                        ->all())
                    ->placeholder('Nowhere else'),
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
                TextEntry::make('page_enabled')
                    ->label('Public page')
                    // The URL rather than the boolean: "on" is not the answer
                    // an admin wants here, "here is the page, go and look at
                    // it" is. Off, the answer is the state.
                    ->state(fn (Package $record): string => $record->hasPage() ? $record->pageUrl() : 'Not published')
                    ->url(fn (Package $record): ?string => $record->hasPage() ? $record->pageUrl() : null)
                    ->openUrlInNewTab()
                    ->color(fn (Package $record): string => $record->hasPage() ? 'primary' : 'gray')
                    ->helperText(fn (Package $record): ?string => match (true) {
                        ! $record->hasPage() => null,
                        $record->pageRequiresAccess() => 'Readable by anyone. Archives and install commands are withheld — this package is served from a private repository.',
                        default => 'Readable by anyone, with '.mb_strtolower($record->pageDownloads()->getLabel()).'.',
                    }),
                TextEntry::make('page_source_path')
                    ->label('Page content')
                    ->visible(fn (Package $record): bool => $record->hasPage())
                    ->state(fn (Package $record): string => match ($record->pageContent()['source']) {
                        'panel' => 'Written here, in the package\'s edit form',
                        'empty' => 'Written here — and still empty, so the page shows the description and install commands alone',
                        'none' => 'None yet — the repository has none of '.implode(', ', $record->pageBodyCandidates()),
                        default => $record->page_source_path.', read from the repository',
                    })
                    ->helperText(fn (Package $record): ?string => $record->page_source_synced_at === null
                        ? null
                        : 'Last read '.$record->page_source_synced_at->diffForHumans()),
                TextEntry::make('description')
                    ->placeholder('-')
                    ->columnSpanFull(),
                Section::make('Install')
                    ->key('install')
                    ->description('Run these in the consuming project. Click a command to copy it.')
                    ->icon(Heroicon::OutlinedCommandLine)
                    ->columnSpanFull()
                    // A package served from several repositories has several
                    // register lines; the select picks which one, bound to
                    // ViewPackage::$installRepository (home by default).
                    ->headerActions([
                        SelectAction::make('installRepository')
                            ->label('Repository')
                            ->options(fn (Package $record): array => $record->repositories->pluck('name', 'id')->all())
                            ->visible(fn (Package $record): bool => $record->repositories->count() > 1),
                        // A read token against the signed-in user, so a private
                        // repository's steps can be followed straight off this
                        // card. Its plain text lives on the page for this
                        // request only — the same one-time reveal as ApiTokens.
                        Action::make('generateToken')
                            ->label('Generate token')
                            ->icon(Heroicon::OutlinedKey)
                            ->modalHeading('Generate a read token')
                            ->modalDescription('Issued to you, with repository read access only. The token is shown once, right after it is created.')
                            ->visible(fn (Package $record, ViewPackage $livewire): bool => ! self::installRepository($record, $livewire)->public)
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->default(fn (Package $record): string => "install: {$record->name}"),
                                DatePicker::make('expires_at')
                                    ->label('Expires')
                                    ->minDate(now()->addDay())
                                    ->helperText('Leave empty for a token that never expires.'),
                            ])
                            ->action(function (array $data, ViewPackage $livewire): void {
                                $new = Token::issue(
                                    auth()->user(),
                                    $data['name'],
                                    [TokenAbility::RepositoryRead],
                                    filled($data['expires_at'] ?? null) ? Carbon::parse($data['expires_at'])->endOfDay() : null,
                                );

                                $livewire->plainTextToken = $new->plainText;

                                Notification::make()
                                    ->success()
                                    ->title('Token created')
                                    ->body('Copy it now — it will not be shown again.')
                                    ->send();
                            }),
                    ])
                    ->schema([
                        TextEntry::make('install_repository')
                            ->label('1. Register this Composer repository (once per project)')
                            ->state(fn (Package $record, ViewPackage $livewire): string => self::installRepository($record, $livewire)->configureCommand())
                            ->fontFamily(FontFamily::Mono)
                            ->copyable()
                            ->copyMessage('Command copied'),
                        TextEntry::make('install_auth')
                            ->label('2. Authenticate (this repository is private)')
                            ->visible(fn (Package $record, ViewPackage $livewire): bool => ! self::installRepository($record, $livewire)->public)
                            ->state(fn (ViewPackage $livewire): string => $livewire->plainTextToken === null
                                ? self::tokenPlaceholder()
                                : e('composer config http-basic.'.request()->getHost()." token {$livewire->plainTextToken}"))
                            ->html()
                            ->fontFamily(fn (ViewPackage $livewire): ?FontFamily => $livewire->plainTextToken === null ? null : FontFamily::Mono)
                            ->color(fn (ViewPackage $livewire): ?string => $livewire->plainTextToken === null ? 'gray' : null)
                            ->copyable(fn (ViewPackage $livewire): bool => $livewire->plainTextToken !== null)
                            ->copyMessage('Command copied')
                            ->helperText(fn (ViewPackage $livewire): ?string => $livewire->plainTextToken === null ? null : 'Shown once — copy it now.'),
                        TextEntry::make('install_require')
                            ->label(fn (Package $record, ViewPackage $livewire): string => (self::installRepository($record, $livewire)->public ? '2' : '3').'. Require the package')
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

    /**
     * The repository the Install card is currently showing steps for.
     */
    private static function installRepository(Package $record, ViewPackage $livewire): Repository
    {
        return $record->repositories->find($livewire->installRepository) ?? $record->composerRepository;
    }

    /**
     * Where to get a token when none was just minted: the user's own page,
     * and the deploy token list for those who may see it.
     */
    private static function tokenPlaceholder(): string
    {
        $link = fn (string $url, string $text): string => '<a href="'.e($url).'" class="font-medium text-primary-600 underline dark:text-primary-400">'.$text.'</a>';

        $deploy = DeployTokenResource::canViewAny() ? $link(DeployTokenResource::getUrl(), 'deploy token') : 'deploy token';

        return 'Generate a token above, or use an existing '.$link(ApiTokens::getUrl(), 'API token').' or '.$deploy.'.';
    }
}
