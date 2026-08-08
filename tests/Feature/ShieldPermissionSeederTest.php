<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ShieldPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_permission_rows(): void
    {
        // The base TestCase already ran the seeder against a fresh database.
        $this->assertGreaterThan(0, Permission::count());
        $this->assertTrue(Permission::where('name', 'ViewAny:Package')->exists());
    }

    /**
     * The first deploy on a hosting provider hits this order: a request or
     * command touches the gate while the permissions table is still empty
     * (caching that emptiness), and only then does `db:seed` run. Because
     * DatabaseSeeder mutes model events, Shield's permission creates never
     * invalidate that cache, and the very first grant resolves names against
     * the stale empty snapshot — dying with PermissionDoesNotExist.
     *
     * Seeding through DatabaseSeeder (not the child seeder directly) is what
     * reproduces this: the WithoutModelEvents trait lives there.
     */
    public function test_it_seeds_over_a_cache_primed_while_the_table_was_empty(): void
    {
        // Builder deletes skip the model events that would clear the cache,
        // just like a database that simply has not been seeded yet.
        DB::table(config('permission.table_names.permissions'))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Prime the cache with the empty table, as any login attempt would.
        $this->assertCount(0, app(PermissionRegistrar::class)->getPermissions());

        $this->seed();

        $this->assertTrue(Permission::where('name', 'ViewAny:Package')->exists());

        // And the account command must survive the same staleness: it syncs
        // the freshly seeded permission ids onto the super admin role.
        Artisan::call('admin:create', ['email' => 'admin@example.com', '--no-interaction' => true]);

        $user = User::where('email', 'admin@example.com')->sole();

        $this->assertSame(
            Permission::count(),
            $user->roles()->sole()->permissions()->count(),
        );
    }
}
