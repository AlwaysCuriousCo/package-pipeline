<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Creates the permission rows that Shield's policies check against.
 *
 * Every environment needs these: without them the generated policies deny
 * everything and even the super admin, whose role is exactly its list of
 * permissions, sees nothing.
 *
 * This asks Shield to derive the permissions from the panel's own resources,
 * pages and widgets rather than replaying a list captured at some earlier
 * date, so it cannot drift as entities are added. The `permissions` option
 * limits it to database rows — policy classes are generated during
 * development and committed, never written at deploy time.
 */
class ShieldPermissionSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('shield:generate', [
            '--all' => true,
            '--option' => 'permissions',
            '--panel' => 'admin',
        ]);
    }
}
