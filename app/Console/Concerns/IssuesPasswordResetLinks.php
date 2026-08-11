<?php

namespace App\Console\Concerns;

use App\Auth\PasswordSetupLink;
use App\Models\User;

/**
 * Console-side password bootstrapping, shared by every command that manages
 * accounts: a password never travels through the environment or the argument
 * list — either a prompt asks for one, or a short-lived link sets it in the
 * browser.
 */
trait IssuesPasswordResetLinks
{
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
     * Print a link to the panel's password reset screen. The token it carries
     * is the same single-use, expiring one the "forgot password" flow issues;
     * it is written to the console rather than emailed so that bootstrapping
     * an account does not depend on working mail.
     */
    private function outputResetLink(User $user): void
    {
        $this->newLine();
        $this->components->warn(sprintf(
            'Set a password with this single-use link, which expires in %d minutes:',
            PasswordSetupLink::TTL_MINUTES,
        ));
        $this->line(PasswordSetupLink::for($user));
        $this->newLine();
    }
}
