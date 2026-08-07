<?php

namespace App\Filament\Resources\Packages\Actions;

use App\Enums\WebhookCoverage;
use App\Models\Package;
use App\Services\GitHub\WebhookRegistrar;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class CreateWebhookAction
{
    /**
     * Adds a webhook to the package's repository so pushes sync it.
     *
     * Only offered where one is actually missing: a package covered by the
     * GitHub App's webhook already hears about every push, and creating a
     * second hook on the repository would only sync it twice.
     */
    public static function make(): Action
    {
        return Action::make('createWebhook')
            ->label(fn (Package $record): string => $record->webhookCoverage() === WebhookCoverage::Failed
                ? 'Retry webhook'
                : 'Create webhook')
            ->icon(Heroicon::OutlinedBolt)
            ->color('gray')
            ->visible(fn (Package $record): bool => in_array(
                $record->webhookCoverage(),
                [WebhookCoverage::None, WebhookCoverage::Failed],
                true,
            ))
            ->requiresConfirmation()
            ->modalHeading('Create a repository webhook')
            ->modalDescription('GitHub will post to this registry whenever a tag or branch on this repository is created, moved or deleted. Creating it needs a credential with admin rights on the repository.')
            ->modalSubmitActionLabel('Create it')
            ->action(function (Package $record): void {
                $coverage = app(WebhookRegistrar::class)->register($record);

                if ($coverage === WebhookCoverage::Repository) {
                    Notification::make()
                        ->success()
                        ->title('Webhook created')
                        ->body("Pushes to {$record->repositoryPath()} will now sync {$record->name}.")
                        ->send();

                    return;
                }

                Notification::make()
                    ->danger()
                    ->title('Webhook could not be created')
                    ->body($record->webhook_error ?? 'GitHub refused the request.')
                    ->persistent()
                    ->send();
            });
    }
}
