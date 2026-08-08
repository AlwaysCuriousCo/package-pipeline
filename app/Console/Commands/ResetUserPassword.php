<?php

namespace App\Console\Commands;

use App\Console\Concerns\IssuesPasswordResetLinks;
use App\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\text;

/**
 * The recovery path for any account: prints the same short-lived signed
 * reset link the "forgot password" flow would email, without depending on
 * working mail.
 */
class ResetUserPassword extends Command
{
    use IssuesPasswordResetLinks;

    protected $signature = 'user:reset-password
        {email? : The account email address; prompted for when omitted}';

    protected $description = 'Print a single-use password reset link for a user';

    public function handle(): int
    {
        $email = $this->argument('email');

        if (blank($email) && $this->canPrompt()) {
            $email = text(label: 'Email address', required: true);
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->components->error(sprintf('No user has the email address %s.', $email ?: '(none given)'));

            return self::FAILURE;
        }

        $this->outputResetLink($user);

        return self::SUCCESS;
    }
}
