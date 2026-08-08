<?php

namespace App\Console\Commands;

use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Facades\Filament;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates the account used to sign in to the admin panel.
 *
 * A password never travels through the environment or the argument list: run
 * interactively and you are prompted for one, run anywhere non-interactive
 * (a deploy hook, a hosting provider's command runner) and the command prints
 * a signed, expiring link that sets the password in the browser instead.
 */
class CreateAdmin extends Command
{
    protected $signature = 'admin:create
        {email? : The account email address; prompted for when omitted}
        {--name= : Display name, applied on create and when explicitly passed}
        {--link : Print a password reset link rather than prompting for a password}';

    protected $description = 'Create or update an admin account without a password passing through the environment';

    /**
     * How long a printed reset link stays usable. Deliberately much shorter
     * than the password broker's expiry: the URL lands in consoles and deploy
     * logs, so it must go stale quickly. The `signed` middleware on the reset
     * route enforces this, making the token unreachable once the URL expires.
     */
    private const LINK_TTL_MINUTES = 5;

    public function handle(): int
    {
        $email = $this->resolveEmail();

        if ($email === null) {
            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $existed = $user->exists;

        if (! $existed) {
            $user->name = $this->option('name') ?? 'Super Admin';

            // A placeholder nobody ever learns. Either the prompt below
            // replaces it, or it stays unusable until the reset link is
            // followed, so the account is never briefly guessable.
            $user->password = Str::password(64);
        } elseif (filled($name = $this->option('name'))) {
            $user->name = $name;
        }

        if (! $this->wantsResetLink()) {
            $user->password = $this->promptForPassword();
        }

        $user->save();

        [$role, $permissions] = $this->grantSuperAdmin($user);

        $this->components->info(sprintf(
            '%s admin account %s with the %s role (%d permissions).',
            $existed ? 'Updated' : 'Created',
            $user->email,
            $role,
            $permissions,
        ));

        if ($this->wantsResetLink()) {
            $this->outputResetLink($user);
        }

        return self::SUCCESS;
    }

    /**
     * Give the account Shield's super admin role, holding every permission
     * that currently exists.
     *
     * The re-sync is the point of running this again: permissions are created
     * by `shield:generate`, so a role granted before a resource existed would
     * not carry its permissions. Re-running after a generate repairs that,
     * which also keeps the ticked boxes in the Roles screen honest.
     *
     * @return array{0: string, 1: int} The role name and how many permissions it now holds.
     */
    private function grantSuperAdmin(User $user): array
    {
        $role = Utils::createRole();

        // Scoped to the role's own guard: syncing a permission belonging to
        // another guard is an exception, not a no-op.
        $permissions = Utils::getPermissionModel()::query()
            ->where('guard_name', $role->guard_name)
            ->pluck('id');

        $role->syncPermissions($permissions);

        $user->unsetRelation('roles')->unsetRelation('permissions')->assignRole($role);

        return [$role->name, $permissions->count()];
    }

    /**
     * The address to act on, prompted for when it was not passed and there is
     * somebody there to answer.
     */
    private function resolveEmail(): ?string
    {
        $email = $this->argument('email');

        if (blank($email) && $this->canPrompt()) {
            $email = text(
                label: 'Email address',
                required: true,
                validate: fn (string $value): ?string => $this->emailError($value),
            );
        }

        if (blank($email)) {
            $this->components->error('An email address is required when the command cannot prompt for one.');

            return null;
        }

        if (filled($error = $this->emailError($email))) {
            $this->components->error($error);

            return null;
        }

        return $email;
    }

    private function emailError(string $email): ?string
    {
        return Validator::make(['email' => $email], ['email' => ['required', 'email']])
            ->errors()
            ->first('email') ?: null;
    }

    /**
     * Prompting is impossible without a terminal, so a non-interactive run
     * falls back to the link rather than failing.
     */
    private function wantsResetLink(): bool
    {
        return $this->option('link') || ! $this->canPrompt();
    }

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

    private function promptForPassword(): string
    {
        return password(
            label: 'Password',
            required: true,
            validate: fn (string $value): ?string => Validator::make(
                ['password' => $value],
                ['password' => ['required', PasswordRule::defaults()]],
            )->errors()->first('password') ?: null,
        );
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
            now()->addMinutes(self::LINK_TTL_MINUTES),
            [
                'email' => $user->getEmailForPasswordReset(),
                'token' => Password::broker()->createToken($user),
            ],
        );

        $this->newLine();
        $this->components->warn(sprintf(
            'Set a password with this single-use link, which expires in %d minutes:',
            self::LINK_TTL_MINUTES,
        ));
        $this->line($url);
        $this->newLine();
    }
}
