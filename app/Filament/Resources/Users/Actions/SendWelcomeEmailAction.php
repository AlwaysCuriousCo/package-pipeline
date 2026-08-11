<?php

namespace App\Filament\Resources\Users\Actions;

use App\Filament\Resources\Users\Schemas\UserForm;
use App\Models\User;
use App\Notifications\WelcomeUser;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

/**
 * Send the welcome email again, from the account it belongs to.
 *
 * The toggle on the Create form covers the first send, and misses it often
 * enough to be worth a second door: the address was wrong, mail was not
 * configured yet, the message was filed as spam, or the account was created
 * from the console, where no toggle exists at all.
 *
 * @see WelcomeUser
 * @see UserForm
 */
class SendWelcomeEmailAction
{
    public static function make(): Action
    {
        return Action::make('sendWelcomeEmail')
            ->label('Send welcome email')
            ->icon(Heroicon::OutlinedEnvelope)
            ->color('gray')
            ->authorize(fn (User $record): bool => auth()->user()?->can('update', $record) ?? false)
            ->requiresConfirmation()
            ->modalHeading('Send the welcome email')
            ->modalDescription(fn (User $record): string => sprintf(
                'A note goes to %s saying the account exists and where to sign in. It carries no password, and sending it changes nothing about the account.',
                $record->email,
            ))
            ->modalSubmitActionLabel('Send it')
            ->action(function (User $record): void {
                $record->notify(new WelcomeUser);

                Notification::make()
                    ->success()
                    ->title('Welcome email queued')
                    ->body("It will reach {$record->email} once the queue worker picks it up.")
                    ->send();
            });
    }
}
