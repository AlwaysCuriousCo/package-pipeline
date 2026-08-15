<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Joaopaulolndev\FilamentEditProfile\Pages\EditProfilePage;

/**
 * The email half of an announcement, written once for all four of them.
 *
 * Every one of these already states its headline and its detail for the bell
 * and again for Slack, and the email wants exactly the same two strings — so
 * this asks for them rather than introducing a third phrasing of the same
 * event. What it adds is the part only email needs: a subject, an absolute
 * link back into the panel, and the footer explaining why the message arrived
 * and where to stop it.
 *
 * The rendering is a hand-written table of inline styles, in the same shape as
 * the welcome email and for the same reasons — resources/views/mail/welcome
 * explains why Laravel's markdown mail components are not used here.
 *
 * Implementors supply `mailAction()`; the three package announcements get it
 * from AboutOnePackage, which already knows the record they are about.
 *
 * @see AboutOnePackage
 */
trait AnnouncedByMail
{
    abstract protected function title(): string;

    abstract protected function body(): string;

    /**
     * Where the email's button goes, and what it says on it.
     *
     * @return array{label: string, url: string}
     */
    abstract protected function mailAction(): array;

    /**
     * Which of the four accents the message is drawn in, matching the colour
     * the same event is given in the bell. A failed sync that arrives looking
     * like a release is a failed sync somebody skims past.
     */
    protected function mailTone(): string
    {
        return 'info';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = (string) config('app.name');
        $action = $this->mailAction();

        return (new MailMessage)
            // The event, not the app: an inbox already shows who sent it, and
            // a subject line prefixed with the registry's name spends the only
            // part of the message a phone shows on something the reader knows.
            ->subject($this->title())
            ->view('mail.announcement', [
                'appName' => $appName,
                'title' => $this->title(),
                'body' => $this->body(),
                'actionLabel' => $action['label'],
                'actionUrl' => $action['url'],
                'tone' => $this->mailTone(),
                'email' => $notifiable instanceof User ? $notifiable->email : null,
                'preferencesUrl' => EditProfilePage::getUrl(),
            ]);
    }
}
