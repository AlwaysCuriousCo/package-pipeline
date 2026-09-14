<?php

namespace App\Support;

use App\Models\Token;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Js;

/**
 * A freshly issued access token, carrying the one and only copy of its
 * plain text. The caller shows it once; only the hash survives.
 */
final readonly class NewToken
{
    public function __construct(
        public Token $token,
        public string $plainText,
    ) {}

    /**
     * The one time the plain text exists, as a toast: persistent so it
     * survives any redirect, dismissed only by whoever copied it.
     *
     * Three copies, because the token is pasted into three different shapes:
     * the bare secret (a CI secret store), the project command (auth.json
     * beside composer.json) and the global one (the machine's auth.json).
     */
    public function notification(string $title): Notification
    {
        $command = $this->composerCommand();

        return Notification::make()
            ->success()
            ->title($title)
            ->body("It will not be shown again.<br><br><code>{$command}</code>")
            ->persistent()
            ->actions([
                // Client-side only: everything copyable ships inside the
                // notification, so copying never round-trips the token.
                $this->copyAction('copyToken', 'Copy token', $this->plainText),
                $this->copyAction('copy', 'Copy project command', $command),
                $this->copyAction('copyGlobal', 'Copy global command', $this->composerCommand(global: true)),
            ]);
    }

    private function copyAction(string $name, string $label, string $value): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon(Heroicon::OutlinedClipboard)
            ->alpineClickHandler('window.navigator.clipboard.writeText('.Js::from($value).')');
    }

    /**
     * The line that configures a Composer client with this token — in the
     * project's auth.json, or the machine's when $global.
     */
    public function composerCommand(bool $global = false): string
    {
        return self::composerCommandFor($this->token->composerUsername(), $this->plainText, $global);
    }

    /**
     * The same line, for the surfaces that kept the plain text and the
     * username as strings rather than the NewToken they came from.
     */
    public static function composerCommandFor(string $username, string $plainText, bool $global = false): string
    {
        return 'composer config '.($global ? '--global ' : '')
            .'http-basic.'.request()->getHost()." {$username} {$plainText}";
    }
}
