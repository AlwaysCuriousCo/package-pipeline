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
            // The body is HTML, and a username is whatever somebody typed:
            // escaped, or a name with a tag in it renders as markup here.
            ->body('It will not be shown again.<br><br><code>'.e($command).'</code>')
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
    public function composerCommand(bool $global = false, ?string $host = null): string
    {
        return self::composerCommandFor($this->token->composerUsername(), $this->plainText, $global, $host);
    }

    /**
     * The same line, for the surfaces that kept the plain text and the
     * username as strings rather than the NewToken they came from — and for
     * the console, which has no request to read a host from.
     */
    public static function composerCommandFor(string $username, string $plainText, bool $global = false, ?string $host = null): string
    {
        $host ??= request()->getHost();

        return 'composer config '.($global ? '--global ' : '')
            ."http-basic.{$host} ".self::argument($username).' '.self::argument($plainText);
    }

    /**
     * One shell argument.
     *
     * The username is free text — a deploy token named "build box" derives one
     * with a space in it — and this line is printed to be pasted into a shell,
     * where an unquoted space is two arguments and `composer config` writes
     * something other than what it says. Quoted only when it has to be, so the
     * ordinary command stays readable.
     *
     * ponytail: POSIX quoting. Fine for sh, zsh and PowerShell; cmd.exe wants
     * double quotes, and would need a second shape of this line to get them.
     */
    private static function argument(string $value): string
    {
        if (preg_match('/^[A-Za-z0-9_@.+\/:-]+$/', $value) === 1) {
            return $value;
        }

        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
