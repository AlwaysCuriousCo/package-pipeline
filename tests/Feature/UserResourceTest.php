<?php

namespace Tests\Feature;

use App\Auth\PasswordSetupLink;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->superAdmin()->create();
        $this->actingAs($this->admin);
    }

    private function role(string $name): RoleContract
    {
        return Role::findOrCreate($name, 'web');
    }

    public function test_a_user_with_no_role_cannot_reach_the_users_resource(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/users')->assertForbidden();
    }

    public function test_the_user_index_lists_records(): void
    {
        $users = User::factory()->count(3)->create();

        $this->get('/admin/users')->assertOk();

        Livewire::test(ListUsers::class)
            ->assertCanSeeTableRecords($users);
    }

    public function test_a_user_can_be_created_with_a_role(): void
    {
        $role = $this->role('panel_user');

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Jo Packager',
                'email' => 'jo@example.com',
                'password' => 'a-long-password',
                'roles' => [$role->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::query()->where('email', 'jo@example.com')->firstOrFail();

        $this->assertTrue($user->hasRole('panel_user'));
        $this->assertTrue(Hash::check('a-long-password', $user->password));
    }

    public function test_the_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'jo@example.com']);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Jo Duplicate',
                'email' => 'jo@example.com',
                'password' => 'a-long-password',
            ])
            ->call('create')
            ->assertHasFormErrors(['email']);
    }

    public function test_a_users_roles_can_be_changed(): void
    {
        $user = User::factory()->create();
        $user->assignRole($this->role('panel_user'));
        $observer = $this->role('observer');

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['roles' => [$observer->getKey()]])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        $this->assertTrue($user->hasRole('observer'));
        $this->assertFalse($user->hasRole('panel_user'));
    }

    public function test_saving_without_a_new_password_keeps_the_existing_one(): void
    {
        $user = User::factory()->create(['password' => 'original-password']);

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['name' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $user->refresh();

        $this->assertSame('Renamed', $user->name);
        $this->assertTrue(Hash::check('original-password', $user->password));
    }

    public function test_an_admin_cannot_change_their_own_roles(): void
    {
        Livewire::test(EditUser::class, ['record' => $this->admin->getKey()])
            ->assertFormFieldDisabled('roles')
            ->fillForm(['roles' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($this->admin->fresh()->hasRole('super_admin'));
    }

    public function test_a_user_can_be_invited_without_anyone_choosing_their_password(): void
    {
        $role = $this->role('panel_user');

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('invite'), data: [
                'email' => 'jo@example.com',
                'roles' => [$role->getKey()],
            ])
            ->assertHasNoActionErrors();

        $user = User::query()->where('email', 'jo@example.com')->firstOrFail();

        $this->assertTrue($user->hasRole('panel_user'));
        // Derived from the address, as `user:add` does.
        $this->assertSame('Jo', $user->name);
        // The stored password is a placeholder nobody was shown, so the only
        // way in is the setup link.
        $this->assertNotEmpty($user->password);
        $this->assertTrue(DB::table('password_reset_tokens')->where('email', 'jo@example.com')->exists());
    }

    public function test_an_invitation_refuses_an_address_already_in_use(): void
    {
        User::factory()->create(['email' => 'jo@example.com']);

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('invite'), data: ['email' => 'jo@example.com'])
            ->assertHasActionErrors(['email' => 'unique']);
    }

    public function test_a_setup_link_can_be_reissued_for_an_existing_account(): void
    {
        $user = User::factory()->create();

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make('setupLink')->table($user))
            ->assertHasNoActionErrors();

        $this->assertTrue(DB::table('password_reset_tokens')->where('email', $user->email)->exists());
    }

    public function test_a_setup_link_opens_the_reset_page_for_the_invited_account(): void
    {
        $user = User::factory()->create();

        // End to end: the sealed payload the notification carries is what
        // actually gets the account in, so an invitation that produced an
        // unusable link would be worse than no invitation at all.
        $this->get(PasswordSetupLink::for($user))
            ->assertRedirect()
            ->assertSessionHas(PasswordSetupLink::SESSION_KEY);
    }

    public function test_inviting_is_hidden_from_a_role_that_cannot_create_users(): void
    {
        $user = User::factory()->create();
        $user->assignRole($this->role('observer'));
        $user->givePermissionTo('ViewAny:User');

        $this->actingAs($user);

        Livewire::test(ListUsers::class)
            ->assertActionHidden(TestAction::make('invite'));
    }

    public function test_an_admin_cannot_delete_their_own_account(): void
    {
        Livewire::test(EditUser::class, ['record' => $this->admin->getKey()])
            ->assertActionHidden('delete');

        Livewire::test(EditUser::class, ['record' => User::factory()->create()->getKey()])
            ->assertActionVisible('delete');
    }
}
