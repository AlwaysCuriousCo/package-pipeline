<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Who gets into /admin, now that Shield decides it.
 */
class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_a_user_with_no_role_cannot_reach_the_panel(): void
    {
        // An account can exist without being an administrator — a leftover
        // row, or one created before its role was granted. It must not be a
        // way in on its own.
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertForbidden();
    }

    public function test_a_super_admin_reaches_the_panel(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->get('/admin')->assertOk();
    }

    public function test_a_user_with_no_role_cannot_reach_a_resource(): void
    {
        Package::factory()->create();

        $this->actingAs(User::factory()->create());

        $this->get('/admin/packages')->assertForbidden();
    }

    public function test_a_super_admin_can_manage_roles(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->get('/admin/shield/roles')->assertOk();
    }

    public function test_a_role_without_permissions_is_not_a_way_into_a_resource(): void
    {
        // Holding *a* role gets you past canAccessPanel; it is the policies
        // that decide what you can see once inside. Only the super admin role
        // is special-cased by the gate.
        $user = User::factory()->create();
        $user->assignRole(Role::create(['name' => 'observer', 'guard_name' => 'web']));

        $this->actingAs($user);

        $this->get('/admin')->assertOk();
        $this->get('/admin/packages')->assertForbidden();
    }
}
