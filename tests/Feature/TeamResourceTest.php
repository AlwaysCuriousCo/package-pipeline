<?php

namespace Tests\Feature;

use App\Filament\Resources\Teams\Pages\CreateTeam;
use App\Filament\Resources\Teams\Pages\EditTeam;
use App\Filament\Resources\Teams\Pages\ListTeams;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Team;
use App\Models\User;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Managing teams from the panel.
 *
 * @see TeamAccessScopingTest for what a team actually grants
 * @see docs/teams.md
 */
class TeamResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_the_index_lists_teams(): void
    {
        $teams = Team::factory()->count(3)->create();

        Livewire::test(ListTeams::class)->assertCanSeeTableRecords($teams);
    }

    public function test_a_team_is_created_with_its_members_and_grants(): void
    {
        $member = User::factory()->create();
        $repository = Repository::factory()->create(['path' => 'internal']);
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        Livewire::test(CreateTeam::class)
            ->fillForm([
                'name' => 'Platform',
                'description' => 'Owns the internal registry.',
                'users' => [$member->getKey()],
                'repositories' => [$repository->getKey()],
                'packages' => [$package->getKey()],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $team = Team::query()->where('name', 'Platform')->sole();

        $this->assertTrue($team->users->contains($member));
        $this->assertTrue($team->repositories->contains($repository));
        $this->assertTrue($team->packages->contains($package));
    }

    public function test_a_team_name_is_unique(): void
    {
        Team::factory()->create(['name' => 'Platform']);

        Livewire::test(CreateTeam::class)
            ->fillForm(['name' => 'Platform'])
            ->call('create')
            ->assertHasFormErrors(['name']);
    }

    public function test_membership_is_edited_on_the_team(): void
    {
        $team = Team::factory()->create();
        $leaving = User::factory()->create();
        $joining = User::factory()->create();

        $team->users()->attach($leaving);

        Livewire::test(EditTeam::class, ['record' => $team->getKey()])
            ->fillForm(['users' => [$joining->getKey()]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$joining->getKey()], $team->users()->pluck('users.id')->all());
    }

    public function test_membership_is_also_edited_on_the_user(): void
    {
        $team = Team::factory()->create(['name' => 'Platform']);
        $user = User::factory()->create();

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->fillForm(['teams' => [$team->getKey()]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($user->teams()->where('teams.id', $team->getKey())->exists());
    }

    /**
     * The four selects on the user form each answer part of "what can this
     * account reach"; this is the whole answer, read from the same scope the
     * registry serves by.
     */
    public function test_the_user_form_reports_effective_access(): void
    {
        $role = Role::findOrCreate('developer', 'web');
        $role->givePermissionTo(['ViewAny:Package', 'View:Package']);

        $internal = Repository::factory()->create(['path' => 'internal', 'public' => false]);
        $granted = Package::factory()->create(['name' => 'acme/widgets', 'repository_id' => $internal->id]);
        Package::factory()->create(['name' => 'acme/secret', 'repository_id' => $internal->id]);
        Package::factory()->create(['name' => 'acme/open']);

        $user = tap(User::factory()->create())->assignRole($role);

        $team = Team::factory()->create(['name' => 'Platform']);
        $team->packages()->attach($granted);
        $team->users()->attach($user);

        Livewire::test(EditUser::class, ['record' => $user->getKey()])
            ->assertSee('2 packages')
            ->assertSee('Platform');
    }

    public function test_the_effective_access_summary_says_when_a_role_makes_grants_moot(): void
    {
        $admin = User::factory()->superAdmin()->create();

        Livewire::test(EditUser::class, ['record' => $admin->getKey()])
            ->assertSee('Unscoped:Package');
    }

    /**
     * The grant pickers are a list of what exists, which is exactly what a
     * scoped account is not supposed to be able to read. Unscoped and
     * preloaded, they would hand every private repository and every package
     * name in the registry to anybody holding Create:Team.
     */
    public function test_the_grant_pickers_offer_only_what_their_author_can_see(): void
    {
        $role = Role::findOrCreate('team-admin', 'web');
        $role->givePermissionTo(['ViewAny:Team', 'View:Team', 'Create:Team', 'Update:Team']);

        $internal = Repository::factory()->create(['path' => 'internal', 'public' => false]);
        $secret = Repository::factory()->create(['path' => 'secret', 'public' => false]);

        $granted = Package::factory()->create(['name' => 'acme/widgets', 'repository_id' => $internal->id]);
        $hidden = Package::factory()->create(['name' => 'acme/secret', 'repository_id' => $secret->id]);

        $author = tap(User::factory()->create())->assignRole($role);
        $author->repositories()->attach($internal);

        $this->actingAs($author);

        $component = Livewire::test(CreateTeam::class);

        $component->assertSchemaComponentExists(
            'repositories',
            checkComponentUsing: fn (Select $field): bool => ! array_key_exists($secret->getKey(), $field->getOptions()),
        );

        $component->assertSchemaComponentExists(
            'packages',
            checkComponentUsing: fn (Select $field): bool => array_key_exists($granted->getKey(), $field->getOptions())
                && ! array_key_exists($hidden->getKey(), $field->getOptions()),
        );
    }

    /**
     * And a grant the author cannot see is not quietly revoked by their edit:
     * the field never held it, so a save that syncs the field must not detach
     * what an unscoped administrator granted.
     */
    public function test_editing_a_team_leaves_grants_the_editor_cannot_see_alone(): void
    {
        $role = Role::findOrCreate('team-admin', 'web');
        $role->givePermissionTo(['ViewAny:Team', 'View:Team', 'Create:Team', 'Update:Team']);

        $internal = Repository::factory()->create(['path' => 'internal', 'public' => false]);
        $secret = Repository::factory()->create(['path' => 'secret', 'public' => false]);

        $visible = Package::factory()->create(['name' => 'acme/widgets', 'repository_id' => $internal->id]);
        $hidden = Package::factory()->create(['name' => 'acme/secret', 'repository_id' => $secret->id]);

        $team = Team::factory()->create();
        $team->packages()->attach([$visible->getKey(), $hidden->getKey()]);

        $author = tap(User::factory()->create())->assignRole($role);
        $author->repositories()->attach($internal);

        $this->actingAs($author);

        Livewire::test(EditTeam::class, ['record' => $team->getKey()])
            ->fillForm(['packages' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([$hidden->getKey()], $team->packages()->pluck('packages.id')->all());
    }

    public function test_the_resource_is_closed_to_a_role_without_the_permission(): void
    {
        $role = Role::findOrCreate('developer', 'web');
        $role->givePermissionTo(['ViewAny:Package']);

        $this->actingAs(tap(User::factory()->create())->assignRole($role));

        $this->get(ListTeams::getUrl())->assertForbidden();
    }

    /**
     * Shield derives the permissions from the panel's own resources, so a new
     * resource has to appear there or its policy denies everything.
     */
    public function test_shield_generates_the_teams_permissions(): void
    {
        foreach (['ViewAny:Team', 'View:Team', 'Create:Team', 'Update:Team', 'Delete:Team'] as $permission) {
            $this->assertTrue(
                Permission::query()->where('name', $permission)->exists(),
                "The seeder did not generate {$permission}.",
            );
        }
    }
}
