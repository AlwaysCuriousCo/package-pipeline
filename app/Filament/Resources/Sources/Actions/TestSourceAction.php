<?php

namespace App\Filament\Resources\Sources\Actions;

use App\Models\Source;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

class TestSourceAction
{
    /**
     * Confirms the source's credentials still work, refreshing the account
     * details and reachable repository count from the provider.
     */
    public static function make(): Action
    {
        return Action::make('test')
            ->label('Test connection')
            ->icon(Heroicon::OutlinedBolt)
            ->color('gray')
            ->action(function (Source $record): void {
                try {
                    $count = $record->verify();
                    $account = $record->refresh()->account;

                    if ($reason = $record->emptyAccessReason($count)) {
                        Notification::make()
                            ->warning()
                            ->title("{$account} is reachable, but shares no repositories")
                            ->body($reason)
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title("{$account} is reachable")
                        ->body(trans_choice('{1} :count repository is available to this source.|[0,*] :count repositories are available to this source.', $count))
                        ->send();
                } catch (Throwable $exception) {
                    Notification::make()
                        ->danger()
                        ->title('Connection failed')
                        ->body($exception->getMessage())
                        ->send();
                }
            })
            ->visible(fn (Source $record): bool => $record->usesInstallation() || filled($record->token));
    }
}
