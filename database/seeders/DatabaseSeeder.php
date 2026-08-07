<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * The admin account is deliberately absent: it is created by
     * `php artisan admin:create`, which keeps the password out of the
     * environment and out of version control. What is seeded here is the
     * permission set that account's role is granted.
     */
    public function run(): void
    {
        $this->call(ShieldPermissionSeeder::class);
    }
}
