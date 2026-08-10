<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\Packages\Pages\ListPackages;
use App\Models\DeployToken;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Team;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZipArchive;

/**
 * A user's reach is their own grants plus their teams'.
 *
 * Package::scopeVisibleToUser is the single chokepoint every read in this app
 * goes through — the panel table, the dashboard widgets, the licence report,
 * the Composer endpoints and /api/v1 — so a mistake there widens access
 * everywhere at once and appears nowhere. This walks the matrix: a team grant
 * is honoured, is revoked when membership ends, composes with a personal grant
 * rather than replacing it, and does nothing at all for somebody who is not in
 * the team.
 *
 * @see docs/teams.md
 */
class TeamAccessScopingTest extends TestCase
{
    use RefreshDatabase;

    private Repository $internal;

    private Repository $other;

    private Package $widgets;

    private Package $gadgets;

    private Package $secret;

    private Package $open;

    protected function setUp(): void
    {
        parent::setUp();

        $this->internal = Repository::factory()->create(['path' => 'internal', 'public' => false]);
        $this->other = Repository::factory()->create(['path' => 'other', 'public' => false]);

        $this->widgets = $this->makeServedPackage('acme/widgets', $this->internal);
        $this->gadgets = $this->makeServedPackage('acme/gadgets', $this->internal);
        $this->secret = $this->makeServedPackage('acme/secret', $this->other);
        $this->open = $this->makeServedPackage('acme/open', Repository::default());
    }

    private function makeServedPackage(string $name, Repository $repository): Package
    {
        $package = Package::factory()->create(['name' => $name, 'repository_id' => $repository->id]);

        $package->versions()->create([
            'version' => 'v1.0.0',
            'reference' => sha1($name),
            'is_dev' => false,
            'metadata' => ['name' => $name, 'version' => 'v1.0.0'],
        ]);

        return $package;
    }

    /**
     * The repositories a user can reach, the default one (whose path is null)
     * last.
     *
     * Ordered here rather than in SQL on purpose: where a null sorts is not
     * settled by the standard, and PostgreSQL puts it last on an ascending
     * sort where SQLite and MySQL put it first — so an assertion that trusted
     * the database's ordering would pass on two of the three engines this is
     * supported on and fail on the third.
     *
     * @return list<string|null>
     */
    private function visibleRepositoryPaths(User $user): array
    {
        return Repository::query()
            ->visibleToUser($user)
            ->pluck('path')
            ->sortBy(fn (?string $path): string => $path ?? "\xff", SORT_STRING)
            ->values()
            ->all();
    }

    /**
     * A publishable artifact: a zip whose composer.json names the package.
     */
    private function makeZip(string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'team-upload-');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('build/composer.json', (string) json_encode(['name' => $name, 'version' => '1.0.0']));
        $zip->close();

        return new UploadedFile($path, 'package.zip', 'application/zip', test: true);
    }

    /**
     * A panel user whose role can read packages but is not unscoped — the
     * shape of an external collaborator's account.
     */
    private function makeScopedUser(): User
    {
        $role = Role::findOrCreate('developer', 'web');
        $role->givePermissionTo(['ViewAny:Package', 'View:Package']);

        return tap(User::factory()->create())->assignRole($role);
    }

    /**
     * The names a user can actually reach, which is what every assertion below
     * is really about.
     *
     * @return list<string>
     */
    private function reach(User $user): array
    {
        return Package::query()->visibleToUser($user)->orderBy('name')->pluck('name')->all();
    }

    public function test_a_user_in_no_team_reaches_exactly_what_they_always_did(): void
    {
        $user = $this->makeScopedUser();

        $this->assertSame(['acme/open'], $this->reach($user));

        $user->packages()->attach($this->widgets);

        $this->assertSame(['acme/open', 'acme/widgets'], $this->reach($user));
    }

    public function test_a_team_package_grant_reaches_its_members(): void
    {
        $user = $this->makeScopedUser();

        $team = Team::factory()->create(['name' => 'Platform']);
        $team->packages()->attach($this->widgets);
        $team->users()->attach($user);

        $this->assertSame(['acme/open', 'acme/widgets'], $this->reach($user));
    }

    public function test_a_team_repository_grant_covers_every_package_in_it(): void
    {
        $user = $this->makeScopedUser();

        $team = Team::factory()->create();
        $team->repositories()->attach($this->internal);
        $team->users()->attach($user);

        $this->assertSame(['acme/gadgets', 'acme/open', 'acme/widgets'], $this->reach($user));
    }

    /**
     * The union, not a replacement: a user holding both keeps both.
     */
    public function test_team_grants_compose_with_personal_grants(): void
    {
        $user = $this->makeScopedUser();
        $user->packages()->attach($this->secret);

        $team = Team::factory()->create();
        $team->packages()->attach($this->widgets);
        $team->users()->attach($user);

        $this->assertSame(['acme/open', 'acme/secret', 'acme/widgets'], $this->reach($user));
    }

    /**
     * A package granted twice over is one package, not two rows in the result.
     */
    public function test_a_grant_held_both_ways_is_not_duplicated(): void
    {
        $user = $this->makeScopedUser();
        $user->packages()->attach($this->widgets);

        $team = Team::factory()->create();
        $team->packages()->attach($this->widgets);
        $team->users()->attach($user);

        $this->assertSame(['acme/open', 'acme/widgets'], $this->reach($user));
    }

    public function test_leaving_a_team_revokes_what_it_granted(): void
    {
        $user = $this->makeScopedUser();

        $team = Team::factory()->create();
        $team->repositories()->attach($this->internal);
        $team->users()->attach($user);

        $this->assertContains('acme/widgets', $this->reach($user));

        $team->users()->detach($user);

        $this->assertSame(['acme/open'], $this->reach($user));
    }

    /**
     * And takes back only what it gave.
     */
    public function test_leaving_a_team_leaves_personal_grants_alone(): void
    {
        $user = $this->makeScopedUser();
        $user->packages()->attach($this->secret);

        $team = Team::factory()->create();
        $team->packages()->attach($this->widgets);
        $team->users()->attach($user);

        $team->users()->detach($user);

        $this->assertSame(['acme/open', 'acme/secret'], $this->reach($user));
    }

    public function test_deleting_a_team_revokes_what_it_granted(): void
    {
        $user = $this->makeScopedUser();

        $team = Team::factory()->create();
        $team->packages()->attach($this->widgets);
        $team->users()->attach($user);

        $team->delete();

        $this->assertSame(['acme/open'], $this->reach($user));
        $this->assertDatabaseCount('package_team', 0);
        $this->assertDatabaseCount('team_user', 0);
    }

    public function test_removing_a_grant_from_a_team_revokes_it_for_every_member(): void
    {
        $first = $this->makeScopedUser();
        $second = $this->makeScopedUser();

        $team = Team::factory()->create();
        $team->packages()->attach($this->widgets);
        $team->users()->attach([$first->getKey(), $second->getKey()]);

        $team->packages()->detach($this->widgets);

        $this->assertSame(['acme/open'], $this->reach($first));
        $this->assertSame(['acme/open'], $this->reach($second));
    }

    /**
     * The case a mistake in the subquery's join would break silently: a team
     * must grant nothing to somebody who is not in it.
     */
    public function test_a_team_grants_nothing_to_a_non_member(): void
    {
        $member = $this->makeScopedUser();
        $stranger = $this->makeScopedUser();

        $team = Team::factory()->create();
        $team->repositories()->attach($this->internal);
        $team->packages()->attach($this->secret);
        $team->users()->attach($member);

        $this->assertContains('acme/widgets', $this->reach($member));
        $this->assertSame(['acme/open'], $this->reach($stranger));
    }

    /**
     * Nor may one team's membership carry another team's grants.
     */
    public function test_membership_of_one_team_does_not_confer_anothers_grants(): void
    {
        $user = $this->makeScopedUser();

        $mine = Team::factory()->create();
        $mine->packages()->attach($this->widgets);
        $mine->users()->attach($user);

        $theirs = Team::factory()->create();
        $theirs->packages()->attach($this->secret);

        $this->assertSame(['acme/open', 'acme/widgets'], $this->reach($user));
    }

    public function test_a_user_in_several_teams_holds_all_of_their_grants(): void
    {
        $user = $this->makeScopedUser();

        $platform = Team::factory()->create();
        $platform->packages()->attach($this->widgets);
        $platform->users()->attach($user);

        $security = Team::factory()->create();
        $security->packages()->attach($this->secret);
        $security->users()->attach($user);

        $this->assertSame(['acme/open', 'acme/secret', 'acme/widgets'], $this->reach($user));
    }

    public function test_an_unscoped_user_is_unaffected_by_teams(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->assertSame(
            ['acme/gadgets', 'acme/open', 'acme/secret', 'acme/widgets'],
            $this->reach($user),
        );
    }

    /**
     * The repository scope has to follow the package one, or a member is told
     * to configure a mount they cannot see.
     */
    public function test_a_team_grant_makes_its_repository_visible(): void
    {
        $user = $this->makeScopedUser();

        $team = Team::factory()->create();
        // Granted one package rather than the repository, which is the case
        // the repository scope has to reach through `packages`.
        $team->packages()->attach($this->widgets);
        $team->users()->attach($user);

        $this->assertSame(['internal', null], $this->visibleRepositoryPaths($user));
    }

    public function test_a_team_repository_grant_makes_the_repository_visible(): void
    {
        $user = $this->makeScopedUser();

        $team = Team::factory()->create();
        $team->repositories()->attach($this->other);
        $team->users()->attach($user);

        $this->assertSame(['other', null], $this->visibleRepositoryPaths($user));
    }

    public function test_the_panel_package_list_shows_a_teams_grants(): void
    {
        $user = $this->makeScopedUser();

        $team = Team::factory()->create();
        $team->packages()->attach($this->widgets);
        $team->users()->attach($user);

        $this->actingAs($user);

        Livewire::test(ListPackages::class)
            ->assertCanSeeTableRecords([$this->widgets, $this->open])
            ->assertCanNotSeeTableRecords([$this->gadgets, $this->secret]);

        $this->get(PackageResource::getUrl('view', ['record' => $this->widgets]))->assertOk();
        $this->get(PackageResource::getUrl('view', ['record' => $this->gadgets]))->assertNotFound();
    }

    /**
     * A personal token sees exactly what its owner does, which now includes
     * what their teams grant.
     */
    public function test_a_personal_token_inherits_its_owners_team_grants(): void
    {
        $user = $this->makeScopedUser();

        $team = Team::factory()->create();
        $team->packages()->attach($this->widgets);
        $team->users()->attach($user);

        $new = Token::issue($user, 'laptop', [TokenAbility::RepositoryRead]);

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/internal/list.json')
            ->assertOk()
            ->assertExactJson(['packageNames' => ['acme/widgets']]);

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/internal/p2/acme/widgets.json')
            ->assertOk();

        // The sibling in the same repository was never granted.
        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/internal/p2/acme/gadgets.json')
            ->assertNotFound();
    }

    public function test_leaving_a_team_closes_the_composer_endpoints_again(): void
    {
        $user = $this->makeScopedUser();

        $team = Team::factory()->create();
        $team->packages()->attach($this->widgets);
        $team->users()->attach($user);

        $new = Token::issue($user, 'laptop', [TokenAbility::RepositoryRead]);

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/internal/p2/acme/widgets.json')
            ->assertOk();

        $team->users()->detach($user);

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/internal/p2/acme/widgets.json')
            ->assertNotFound();
    }

    public function test_the_management_api_honours_team_grants(): void
    {
        $user = $this->makeScopedUser();

        $team = Team::factory()->create();
        $team->packages()->attach($this->widgets);
        $team->users()->attach($user);

        $new = Token::issue($user, 'ci', [TokenAbility::ApiRead]);

        $this->withToken($new->plainText)
            ->getJson('/api/v1/packages')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'acme/open')
            ->assertJsonPath('data.1.name', 'acme/widgets')
            ->assertJsonCount(2, 'data');

        $this->withToken($new->plainText)
            ->getJson("/api/v1/packages/{$this->gadgets->getKey()}")
            ->assertNotFound();
    }

    /**
     * A team holds the same grants a person can be given individually, so one
     * of them must not confer publishing rights where the other does — a grant
     * that meant different things depending on how it was held would be a
     * second, invisible kind of grant.
     */
    public function test_a_team_repository_grant_confers_the_right_to_publish(): void
    {
        config(['filesystems.dists' => 's3']);
        Storage::fake('s3');

        $user = $this->makeScopedUser();
        $new = Token::issue($user, 'ci', [TokenAbility::RepositoryWrite]);

        $upload = fn (): TestResponse => $this->withToken($new->plainText)->post(
            '/r/internal/upload/acme/published',
            ['file' => $this->makeZip('acme/published')],
        );

        $upload()->assertForbidden();

        $team = Team::factory()->create();
        $team->repositories()->attach($this->internal);
        $team->users()->attach($user);

        $upload()->assertCreated();
    }

    public function test_a_team_package_grant_confers_the_right_to_sync_that_package(): void
    {
        Queue::fake();

        $user = $this->makeScopedUser();
        $new = Token::issue($user, 'ci', [TokenAbility::ApiWrite]);

        $this->withToken($new->plainText)
            ->postJson("/api/v1/packages/{$this->widgets->getKey()}/sync")
            ->assertNotFound();

        $team = Team::factory()->create();
        $team->packages()->attach($this->widgets);
        $team->users()->attach($user);

        $this->withToken($new->plainText)
            ->postJson("/api/v1/packages/{$this->widgets->getKey()}/sync")
            ->assertAccepted();
    }

    /**
     * Teams are for people. A deploy token authenticates as a machine, holds
     * its own grants, and must not pick anything up from a team it could never
     * be a member of.
     */
    public function test_a_deploy_token_is_untouched_by_teams(): void
    {
        $team = Team::factory()->create();
        $team->packages()->attach($this->widgets);

        $deploy = DeployToken::factory()->create();
        $deploy->packages()->attach($this->secret);

        $new = Token::issue($deploy, 'ci', [TokenAbility::RepositoryRead]);

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/internal/list.json')
            ->assertOk()
            ->assertExactJson(['packageNames' => []]);

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/other/list.json')
            ->assertOk()
            ->assertExactJson(['packageNames' => ['acme/secret']]);
    }

    /**
     * An anonymous caller has no user and therefore no teams; the branch that
     * short-circuits for one must not accidentally admit the other.
     */
    public function test_an_anonymous_caller_still_sees_public_repositories_only(): void
    {
        $team = Team::factory()->create();
        $team->packages()->attach($this->widgets);

        $this->getJson('/list.json')
            ->assertOk()
            ->assertExactJson(['packageNames' => ['acme/open']]);

        // A private repository refuses an anonymous caller outright, which is
        // where it stops before any team is consulted at all.
        $this->getJson('/r/internal/list.json')->assertUnauthorized();
    }
}
