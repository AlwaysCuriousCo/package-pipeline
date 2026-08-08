<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run the command the way a deploy hook or a hosting provider's command
     * runner would, and hand back everything it printed.
     */
    private function runNonInteractively(array $arguments = []): string
    {
        Artisan::call('admin:create', [...$arguments, '--no-interaction' => true]);

        return Artisan::output();
    }

    private function linkFrom(string $output): string
    {
        $this->assertMatchesRegularExpression('#https?://\S+#', $output, 'The command printed no reset link.');

        preg_match('#https?://\S+#', $output, $matches);

        return $matches[0];
    }

    public function test_it_creates_an_account_and_prints_a_working_reset_link(): void
    {
        $output = $this->runNonInteractively(['--email' => 'admin@example.com']);

        $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'name' => 'Super Admin']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'admin@example.com']);

        // The link has to actually resolve: it is signed, so a wrong route
        // name or a panel without password reset enabled would 403 or 404.
        $this->get($this->linkFrom($output))->assertOk();
    }

    public function test_it_grants_the_super_admin_role(): void
    {
        $this->runNonInteractively(['--email' => 'admin@example.com']);

        $user = User::where('email', 'admin@example.com')->sole();

        $this->assertTrue($user->hasRole('super_admin'));

        // The role is exactly its permissions — nothing is granted by a gate
        // bypass — so the seeded permission set has to be on it.
        $this->assertSame(
            Permission::count(),
            $user->roles()->sole()->permissions()->count(),
        );
        $this->assertTrue($user->can('View:Package'));
    }

    public function test_it_resyncs_permissions_onto_the_role(): void
    {
        // Stand in for a permission that shield:generate created after the
        // role was first handed out.
        Permission::create(['name' => 'View:SomethingNew', 'guard_name' => 'web']);

        $this->runNonInteractively(['--email' => 'admin@example.com']);

        $role = Role::where('name', 'super_admin')->sole();

        $this->assertTrue($role->hasPermissionTo('View:SomethingNew'));
    }

    public function test_a_permission_added_later_is_picked_up_on_a_second_run(): void
    {
        $this->runNonInteractively(['--email' => 'admin@example.com']);

        Permission::create(['name' => 'View:AddedAfterwards', 'guard_name' => 'web']);

        $role = Role::where('name', 'super_admin')->sole();
        $this->assertFalse($role->hasPermissionTo('View:AddedAfterwards'));

        $output = $this->runNonInteractively(['--email' => 'admin@example.com']);

        $this->assertTrue($role->fresh()->hasPermissionTo('View:AddedAfterwards'));
        $this->assertStringContainsString(Permission::count().' permissions', $output);
    }

    public function test_it_grants_the_role_to_an_account_that_already_exists(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);

        $this->assertFalse($user->hasRole('super_admin'));

        $this->runNonInteractively(['--email' => 'admin@example.com']);

        $this->assertTrue($user->fresh()->hasRole('super_admin'));
    }

    public function test_the_new_account_has_no_guessable_password(): void
    {
        $this->runNonInteractively(['--email' => 'admin@example.com']);

        $user = User::where('email', 'admin@example.com')->sole();

        foreach (['', 'password', 'Super Admin', 'admin@example.com'] as $guess) {
            $this->assertFalse(Hash::check($guess, $user->password));
        }
    }

    public function test_a_name_can_be_given_on_create(): void
    {
        $this->runNonInteractively(['--email' => 'admin@example.com', '--name' => 'Ada Lovelace']);

        $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'name' => 'Ada Lovelace']);
    }

    public function test_it_reuses_an_existing_account_rather_than_duplicating_it(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com', 'name' => 'Ada Lovelace']);

        $output = $this->runNonInteractively(['--email' => 'admin@example.com']);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame('Ada Lovelace', $user->fresh()->name);
        $this->assertStringContainsString('Updated', $output);

        // Issuing a link must not disturb the password already in place; the
        // account keeps working until the link is followed.
        $this->assertSame($user->password, $user->fresh()->password);
    }

    public function test_an_existing_account_can_be_renamed(): void
    {
        User::factory()->create(['email' => 'admin@example.com', 'name' => 'Ada Lovelace']);

        $this->runNonInteractively(['--email' => 'admin@example.com', '--name' => 'Grace Hopper']);

        $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'name' => 'Grace Hopper']);
    }

    public function test_it_prompts_for_email_and_name_when_neither_is_passed(): void
    {
        $this->artisan('admin:create')
            ->expectsQuestion('Email address', 'admin@example.com')
            ->expectsQuestion('Display name', 'Ada Lovelace')
            ->expectsQuestion('Password', 'a-strong-enough-password')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'name' => 'Ada Lovelace']);
    }

    public function test_an_existing_account_is_not_prompted_for_a_name(): void
    {
        User::factory()->create(['email' => 'admin@example.com', 'name' => 'Ada Lovelace']);

        $this->artisan('admin:create', ['--email' => 'admin@example.com'])
            ->expectsQuestion('Password', 'a-strong-enough-password')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'admin@example.com', 'name' => 'Ada Lovelace']);
    }

    public function test_it_rejects_an_invalid_email_address(): void
    {
        $this->artisan('admin:create', ['--email' => 'not-an-address'])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_it_prompts_for_a_password_when_run_interactively(): void
    {
        $this->artisan('admin:create', ['--email' => 'admin@example.com'])
            ->expectsQuestion('Display name', 'Super Admin')
            ->expectsQuestion('Password', 'a-strong-enough-password')
            ->assertSuccessful();

        $user = User::where('email', 'admin@example.com')->sole();

        $this->assertTrue(Hash::check('a-strong-enough-password', $user->password));

        // Nothing to expire when a password was set here and now.
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_the_reset_link_goes_stale_after_five_minutes(): void
    {
        $output = $this->runNonInteractively(['--email' => 'admin@example.com']);

        $link = $this->linkFrom($output);

        $this->assertStringContainsString('expires in 5 minutes', $output);

        // The URL ends up in deploy logs, so its signature must expire well
        // before the underlying token would.
        $this->travel(6)->minutes();

        $this->get($link)->assertForbidden();
    }

    public function test_the_link_flag_skips_the_password_prompt(): void
    {
        $output = $this->runNonInteractively(['--email' => 'admin@example.com', '--link' => true]);

        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'admin@example.com']);
        $this->get($this->linkFrom($output))->assertOk();
    }
}
