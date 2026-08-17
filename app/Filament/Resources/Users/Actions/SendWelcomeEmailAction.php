<?php

namespace App\Filament\Resources\Users\Actions;

use App\Filament\Resources\Users\Schemas\UserForm;
use App\Models\User;
use App\Notifications\WelcomeUser;
use App\Support\MailDelivery;
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
            ->modalDescription(function (User $record): string {
                $description = sprintf(
                    'A note goes to %s saying the account exists and where to sign in. It carries no password, and sending it changes nothing about the account.',
                    $record->email,
                );

                if (MailDelivery::delivers()) {
                    return $description;
                }

                return $description.sprintf(
                    ' Mail is set to the `%s` driver, which delivers nothing — the message will be accepted and discarded.',
                    MailDelivery::driver(),
                );
            })
            ->modalSubmitActionLabel('Send it')
            ->action(function (User $record): void {
                $record->notify(new WelcomeUser);

                if (! MailDelivery::delivers()) {
                    Notification::make()
                        ->warning()
                        ->title('Welcome email went nowhere')
                        ->body(sprintf(
                            'Mail is set to the `%s` driver, so nothing was delivered to %s. Configure a mailer and send it again.',
                            MailDelivery::driver(),
                            $record->email,
                        ))
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Welcome email queued')
                    ->body("It will reach {$record->email} once the queue worker picks it up.")
                    ->send();
            });
    }
}
