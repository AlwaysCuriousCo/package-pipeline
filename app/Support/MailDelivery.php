<?php

namespace App\Support;

use App\Filament\Resources\Users\Actions\SendWelcomeEmailAction;
use App\Filament\Resources\Users\Schemas\UserForm;

/**
 * Whether the configured mailer actually puts mail on the wire.
 *
 * A self-hosted registry is routinely stood up before mail is configured, and
 * the drivers it sits on until then — `log` and `array` — accept every message
 * and deliver none. Nothing throws, so any screen that reports "sent" from the
 * return of a send is reporting on a message that went to a file or to memory.
 *
 * Two screens ask the question about the same email, and they have to agree:
 * the Create form decides whether the welcome toggle starts on, and the resend
 * action decides whether to promise delivery.
 *
 * @see UserForm
 * @see SendWelcomeEmailAction
 */
final class MailDelivery
{
    /**
     * Drivers that accept a message and deliver nothing.
     *
     * @var array<int, string>
     */
    private const DISCARDING = ['log', 'array'];

    public static function driver(): string
    {
        return (string) config('mail.default');
    }

    public static function delivers(): bool
    {
        return ! in_array(self::driver(), self::DISCARDING, strict: true);
    }
}
