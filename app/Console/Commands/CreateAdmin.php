<?php

namespace App\Console\Commands;

use App\Console\Concerns\IssuesPasswordResetLinks;
use App\Models\User;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Spatie\Permission\PermissionRegistrar;

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
    use IssuesPasswordResetLinks;

    protected $signature = 'admin:create
        {--email= : The account email address; prompted for when omitted}
        {--name= : Display name; prompted for on create, applied on update when passed}
        {--link : Print a password reset link rather than prompting for a password}';

    protected $description = 'Create or update an admin account without a password passing through the environment';

    public function handle(): int
    {
        $email = $this->resolveEmail();

        if ($email === null) {
            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $existed = $user->exists;

        if (! $existed) {
            $user->name = $this->resolveName();

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
        // syncPermissions() resolves ids through Spatie's permission cache;
        // if that cache predates the seeding of the permissions (easy to do
        // in a hosted command runner), the sync throws rather than syncing.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

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
        $email = $this->option('email');

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
     * The display name for a new account: the option when passed, otherwise a
     * prompt when somebody is there to answer. A non-interactive run (a deploy
     * hook) still gets the historical default rather than stopping.
     */
    private function resolveName(): string
    {
        $name = $this->option('name');

        if (blank($name) && $this->canPrompt()) {
            $name = text(
                label: 'Display name',
                default: 'Super Admin',
                required: true,
            );
        }

        return blank($name) ? 'Super Admin' : $name;
    }

    /**
     * Prompting is impossible without a terminal, so a non-interactive run
     * falls back to the link rather than failing.
     */
    private function wantsResetLink(): bool
    {
        return $this->option('link') || ! $this->canPrompt();
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
}
