<?php

namespace App\Filament\Resources\Sources\Actions;

use App\Enums\WebhookState;
use App\Services\GitHub\GitHubApp;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class RecheckWebhookAction
{
    /**
     * Ask GitHub again what the app's webhook is doing.
     *
     * The answer is cached for a few minutes, which is right for a page that
     * reports it on every load and wrong for the moment straight after
     * switching the webhook on — which is exactly when someone wants to know
     * whether it worked.
     */
    public static function make(): Action
    {
        return Action::make('recheckWebhook')
            ->label('Re-check')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->action(function (): void {
                $app = app(GitHubApp::class);

                $app->forgetWebhookConfiguration();

                $state = $app->webhookState();

                Notification::make()
                    ->status($state === WebhookState::Delivering ? 'success' : 'warning')
                    ->title($state->getLabel())
                    ->body($state->remedy((string) ($app->deliveringTo() ?? ''))
                        ?? 'GitHub confirms the app posts to this registry. Pushes to any repository in this installation will sync their packages.')
                    ->persistent($state !== WebhookState::Delivering)
                    ->send();
            });
    }
}
