<?php

namespace App\Filament\Resources\Sources\Schemas;

use App\Enums\WebhookState;
use App\Filament\Resources\Sources\Actions\RecheckWebhookAction;
use App\Models\Source;
use App\Services\GitHub\GitHubApp;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SourceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('name'),
                TextEntry::make('provider')
                    ->badge(),
                TextEntry::make('account')
                    ->label('Organisation or user')
                    ->url(fn (Source $record): ?string => $record->account
                        ? "https://{$record->provider->host()}/{$record->account}"
                        : null)
                    ->openUrlInNewTab()
                    ->color('primary')
                    ->placeholder('Not connected yet'),
                TextEntry::make('account_type')
                    ->label('Account type')
                    ->placeholder('-'),
                TextEntry::make('connected_at')
                    ->label('Connected')
                    ->since()
                    ->placeholder('Never'),
                TextEntry::make('installation_id')
                    ->label('Authentication')
                    ->badge()
                    ->color(fn (Source $record): string => $record->usesInstallation() ? 'success' : 'warning')
                    // Token-based sources have no installation id, so the
                    // state is filled in from the record rather than the
                    // column to keep the entry from falling back to a dash.
                    ->state(fn (Source $record): string => match (true) {
                        $record->usesInstallation() => "GitHub App installation #{$record->installation_id}",
                        filled($record->token) => 'Access token',
                        default => 'None',
                    }),
                TextEntry::make('metadata.repository_selection')
                    ->label('Repository access')
                    ->formatStateUsing(fn (string $state): string => $state === 'all'
                        ? 'All repositories in the account'
                        : 'Only the selected repositories')
                    ->placeholder('-'),
                TextEntry::make('metadata.repository_count')
                    ->label('Repositories reachable')
                    ->placeholder('Unknown — run "Test connection"'),
                TextEntry::make('base_url')
                    ->label('API base URL')
                    ->placeholder('https://api.github.com'),
                TextEntry::make('connection_error')
                    ->label('Connection error')
                    ->color('danger')
                    ->placeholder('None')
                    ->columnSpanFull(),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
                self::webhook(),
            ]);
    }

    /**
     * How pushes to this source's repositories get back here.
     *
     * This belongs on the source rather than on each package: the app's
     * webhook is set up once and covers every repository in the installation,
     * so a source that has just been connected is exactly where an admin is
     * standing when it is worth doing — and where nothing else would mention
     * it until a package quietly failed to sync itself.
     */
    private static function webhook(): Section
    {
        return Section::make('Auto-sync')
            ->key('webhook')
            ->visible(fn (Source $record): bool => $record->usesInstallation())
            ->description('One webhook on the GitHub App covers every repository in every installation. Without it, each package carries a webhook on its own repository instead, which needs the app to have "Webhooks: Read and write".')
            ->headerActions([RecheckWebhookAction::make()])
            ->columnSpanFull()
            ->schema([
                TextEntry::make('webhook_status')
                    ->label('GitHub App webhook')
                    ->badge()
                    // Asked of GitHub rather than inferred from this app's own
                    // configuration: a secret set here says nothing about
                    // whether the app was ever given a webhook to sign with it.
                    ->state(fn (): WebhookState => app(GitHubApp::class)->webhookState())
                    ->helperText(fn (): ?string => app(GitHubApp::class)->webhookState()
                        ->remedy((string) (app(GitHubApp::class)->deliveringTo() ?? ''))),
                TextEntry::make('webhook_url')
                    ->label('Payload URL')
                    ->state(fn (): string => app(GitHubApp::class)->webhookUrl())
                    ->copyable()
                    ->helperText('Set this as the webhook URL on the GitHub App, with content type application/json, and subscribe it to Push, Branch or tag creation, and Branch or tag deletion.'),
                TextEntry::make('webhook_secret')
                    ->label('Webhook secret')
                    ->visible(fn (): bool => app(GitHubApp::class)->webhookState() === WebhookState::NoSecret)
                    // Only ever a suggestion for a secret that is not set yet;
                    // a configured one lives in the environment and is not
                    // read back out to a browser.
                    ->state(fn (): string => app(GitHubApp::class)->suggestedWebhookSecret())
                    ->copyable()
                    ->columnSpanFull()
                    ->helperText(fn (): string => 'Not set yet — here is one to use. Paste it into the app\'s webhook secret field, and add it to this app\'s environment as GITHUB_APP_WEBHOOK_SECRET='
                        .app(GitHubApp::class)->suggestedWebhookSecret().' — then restart or clear the config cache.'),
            ]);
    }
}
