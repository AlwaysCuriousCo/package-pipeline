<?php

namespace App\Console\Concerns;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

/**
 * Console-side password bootstrapping, shared by every command that manages
 * accounts: a password never travels through the environment or the argument
 * list — either a prompt asks for one, or a short-lived signed link sets it
 * in the browser.
 */
trait IssuesPasswordResetLinks
{
    /**
     * How long a printed reset link stays usable. Deliberately much shorter
     * than the password broker's expiry: the URL lands in consoles and deploy
     * logs, so it must go stale quickly. The `signed` middleware on the reset
     * route enforces this, making the token unreachable once the URL expires.
     */
    private static int $linkTtlMinutes = 5;

    /**
     * Whether Laravel Prompts would actually accept input, which is stricter
     * than Symfony's notion of interactive: some command runners (Laravel
     * Cloud's among them) leave the input flagged interactive while STDIN is
     * not a TTY, and a required prompt then aborts instead of asking.
     */
    private function canPrompt(): bool
    {
        return $this->input->isInteractive()
            && ((defined('STDIN') && stream_isatty(STDIN)) || $this->laravel->runningUnitTests());
    }

    /**
     * Print a signed link to the panel's password reset screen. The token is
     * the same single-use, expiring one the "forgot password" flow issues; it
     * is written to the console rather than emailed so that bootstrapping an
     * account does not depend on working mail.
     */
    private function outputResetLink(User $user): void
    {
        // Filament's own getResetPasswordUrl() signs without an expiry,
        // leaving the link alive as long as the token (an hour by default).
        $url = URL::temporarySignedRoute(
            Filament::getPanel('admin')->generateRouteName('auth.password-reset.reset'),
            now()->addMinutes(static::$linkTtlMinutes),
            [
                'email' => $user->getEmailForPasswordReset(),
                'token' => Password::broker()->createToken($user),
            ],
        );

        $this->newLine();
        $this->components->warn(sprintf(
            'Set a password with this single-use link, which expires in %d minutes:',
            static::$linkTtlMinutes,
        ));
        $this->line($url);
        $this->newLine();
    }
}
