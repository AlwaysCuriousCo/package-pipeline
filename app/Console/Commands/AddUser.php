<?php

namespace App\Console\Commands;

use App\Console\Concerns\IssuesPasswordResetLinks;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\text;

/**
 * Provisions a regular panel account. `admin:create` remains the tool for
 * the super admin; this one creates everyone else, with whatever roles an
 * admin has shaped in the panel.
 */
class AddUser extends Command
{
    use IssuesPasswordResetLinks;

    protected $signature = 'user:add
        {email? : The account email address; prompted for when omitted}
        {--name= : Display name; derived from the email when omitted}
        {--role=* : Role names to assign; must already exist}';

    protected $description = 'Create a panel user and print their password setup link';

    public function handle(): int
    {
        $email = $this->argument('email');

        if (blank($email) && $this->canPrompt()) {
            $email = text(
                label: 'Email address',
                required: true,
                validate: fn (string $value): ?string => $this->emailError($value),
            );
        }

        if (blank($email) || filled($error = $this->emailError((string) $email))) {
            $this->components->error($error ?? 'An email address is required when the command cannot prompt for one.');

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->components->error("A user with the email {$email} already exists. Manage them in the panel, or use user:reset-password.");

            return self::FAILURE;
        }

        $roles = $this->resolveRoles();

        if ($roles === null) {
            return self::FAILURE;
        }

        $user = User::query()->create([
            'name' => $this->option('name') ?: Str::headline(Str::before((string) $email, '@')),
            'email' => $email,
            // A placeholder nobody ever learns; the printed reset link is how
            // the account gets a real password.
            'password' => Str::password(64),
        ]);

        $user->assignRole($roles);

        $this->components->info(sprintf(
            'Created %s%s.',
            $user->email,
            $roles === [] ? ' with no roles' : ' with the '.implode(', ', $roles).' role'.(count($roles) === 1 ? '' : 's'),
        ));

        if ($roles === []) {
            $this->components->warn('Without a role the account cannot sign in to the panel. Assign one in the panel, or re-run with --role.');
        }

        $this->outputResetLink($user);

        return self::SUCCESS;
    }

    /**
     * The role names to assign, validated against the roles that exist —
     * a typo must fail loudly, not produce an account that cannot log in.
     *
     * @return list<string>|null null when a named role does not exist
     */
    private function resolveRoles(): ?array
    {
        $available = Role::query()->where('guard_name', 'web')->orderBy('name')->pluck('name')->all();

        $roles = array_values(array_unique((array) $this->option('role')));

        if ($roles === [] && $this->canPrompt() && $available !== []) {
            $roles = array_values(multiselect(
                label: 'Roles to assign',
                options: $available,
                hint: 'Without one, the account cannot sign in to the panel.',
            ));
        }

        $missing = array_diff($roles, $available);

        if ($missing !== []) {
            $this->components->error(sprintf(
                'Unknown role%s: %s. Available: %s.',
                count($missing) === 1 ? '' : 's',
                implode(', ', $missing),
                $available === [] ? '(none — create roles in the panel first)' : implode(', ', $available),
            ));

            return null;
        }

        return $roles;
    }

    private function emailError(string $email): ?string
    {
        return Validator::make(['email' => $email], ['email' => ['required', 'email']])
            ->errors()
            ->first('email') ?: null;
    }
}
