<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class UserSeeder extends Seeder
{
    /**
     * The super admin's email address.
     */
    public const SUPER_ADMIN_EMAIL = 'tim@alwayscurious.co';

    /**
     * Seed the super admin account.
     *
     * The password is read from the SUPER_ADMIN_PASSWORD environment variable
     * so it never lives in version control. Re-running the seeder resets the
     * password to whatever .env currently holds.
     */
    public function run(): void
    {
        $password = env('SUPER_ADMIN_PASSWORD');

        if (blank($password)) {
            throw new RuntimeException(
                'SUPER_ADMIN_PASSWORD is not set. Add it to your .env file before seeding.'
            );
        }

        User::updateOrCreate(
            ['email' => self::SUPER_ADMIN_EMAIL],
            [
                'name' => 'Tim Wood',
                // The User model casts `password` to `hashed`, so this is
                // hashed on save rather than stored in plain text.
                'password' => $password,
            ],
        );

        $this->command?->info('Seeded super admin: '.self::SUPER_ADMIN_EMAIL);
    }
}
