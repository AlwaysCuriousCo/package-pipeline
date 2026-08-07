<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Super Admin
    |--------------------------------------------------------------------------
    |
    | Credentials applied by Database\Seeders\UserSeeder. The email address and
    | password have no defaults on purpose: no real address, password or other
    | personal data belongs in version control, so the seeder fails loudly
    | rather than falling back to a value committed here.
    |
    | They are read here rather than with env() inside the seeder because
    | `php artisan config:cache` stops .env from being loaded, which makes
    | env() return null everywhere outside of config files.
    |
    */

    'super_admin' => [
        'email' => env('SUPER_ADMIN_EMAIL'),
        'name' => env('SUPER_ADMIN_NAME', 'Super Admin'),
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],

];
