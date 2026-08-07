<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class UserSeeder extends Seeder
{
    /**
     * Seed the super admin account.
     *
     * The email address, display name and password all come from the
     * environment so that no personal data lives in version control. They are
     * read through config rather than env() directly, because env() returns
     * null outside of config files once the config has been cached. Re-running
     * the seeder resets the account to whatever the environment currently
     * holds.
     */
    public function run(): void
    {
        $email = config('seeding.super_admin.email');
        $password = config('seeding.super_admin.password');

        foreach (['SUPER_ADMIN_EMAIL' => $email, 'SUPER_ADMIN_PASSWORD' => $password] as $variable => $value) {
            if (blank($value)) {
                throw new RuntimeException(
                    "{$variable} is not set. Add it to your .env file before seeding, "
                    .'and re-run `php artisan config:cache` if the config is cached.'
                );
            }
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => config('seeding.super_admin.name'),
                // The User model casts `password` to `hashed`, so this is
                // hashed on save rather than stored in plain text.
                'password' => $password,
            ],
        );

        $this->command?->info('Seeded super admin: '.$email);
    }
}
