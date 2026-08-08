<?php

namespace Database\Seeders;

use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;

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
        // Shield creates the rows in a way that skips Spatie's cache-clearing
        // hook, then resolves them *through* the cache to grant them to the
        // super admin role. A cache primed while the table was empty (any web
        // request or command before first seeding does this) then aborts the
        // generate with PermissionDoesNotExist — so start from a cold cache,
        // and leave a cold one behind for whatever runs next.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Artisan::call('shield:generate', [
            '--all' => true,
            '--option' => 'permissions',
            '--panel' => 'admin',
        ]);

        // Packistry's `unscoped`, spelled in Shield's permission grammar: the
        // row-level visibility bypass Package::visibleToUser() checks. Shield
        // only derives entity CRUD permissions, so it is declared here; any
        // role can be granted it on the Roles screen's custom permissions tab.
        Utils::getPermissionModel()::firstOrCreate([
            'name' => 'Unscoped:Package',
            'guard_name' => 'web',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
