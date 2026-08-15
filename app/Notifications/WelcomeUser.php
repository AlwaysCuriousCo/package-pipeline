<?php

namespace App\Notifications;

use App\Auth\PasswordSetupLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The one email an account's owner gets when an admin provisions it.
 *
 * Deliberately says nothing about credentials. The panel's Create form has an
 * admin type a password, and the invitation flow issues a link that lives five
 * minutes — neither survives a trip through a mail queue worth putting in
 * writing, and an email carrying either is the exact shape this registry
 * already goes out of its way to avoid.
 *
 * @see PasswordSetupLink for why setup links are shown, not sent.
 */
class WelcomeUser extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = (string) config('app.name');

        return (new MailMessage)
            ->subject("Your {$appName} account is ready")
            ->view('mail.welcome', [
                'appName' => $appName,
                'name' => $notifiable->name,
                'email' => $notifiable->email,
                'signInUrl' => route('filament.admin.auth.login'),
                'passwordResetUrl' => route('filament.admin.auth.password-reset.request'),
            ]);
    }
}
