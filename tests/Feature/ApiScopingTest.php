<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Jobs\SyncPackageJob;
use App\Models\DeployToken;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The API's access control, which is not its own: every query runs through the
 * same visibleTo() scope the Composer endpoints and the panel narrow by, and
 * every mutation through the same grant rules the artifact upload obeys.
 *
 * Three rules, kept apart on purpose. Whether a caller may *see* a package
 * decides 404 or not — a 403 would confirm the name to a token that could
 * never have fetched it. Whether its grants reach far enough to *change* one is
 * asked afterwards, and being able to read a public repository answers it with
 * no. And for a token issued by a person, whether that person's role permits
 * the change at all, which is the panel's question asked over HTTP.
 */
class ApiScopingTest extends TestCase
{
    use RefreshDatabase;

    private Repository $mine;

    private Repository $theirs;

    private Repository $open;

    private Package $myPackage;

    private Package $theirPackage;

    private Package $openPackage;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([SyncPackageJob::class]);

        $this->mine = Repository::factory()->create(['path' => 'mine']);
        $this->theirs = Repository::factory()->create(['path' => 'theirs']);
        $this->open = Repository::factory()->public()->create(['path' => 'open']);

        $this->myPackage = Package::factory()->create(['name' => 'mine/widgets', 'repository_id' => $this->mine->id]);
        $this->theirPackage = Package::factory()->create(['name' => 'theirs/widgets', 'repository_id' => $this->theirs->id]);
        $this->openPackage = Package::factory()->create(['name' => 'open/widgets', 'repository_id' => $this->open->id]);
    }

    /**
     * A CI credential granted one repository — the shape the API is for.
     */
    private function deployTokenFor(Repository $repository): string
    {
        $principal = DeployToken::factory()->create();
        $principal->repositories()->attach($repository);

        return Token::issue($principal, 'ci', [
            TokenAbility::ApiRead, TokenAbility::ApiWrite, TokenAbility::ApiDelete,
        ])->plainText;
    }

    /**
     * A panel user whose role holds exactly the named permissions — the other
     * half of what a personal token may do.
     *
     * @param  list<string>  $permissions
     */
    private function userWithRole(array $permissions): User
    {
        $role = Role::create(['name' => 'role-'.Role::query()->count(), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);

        return tap(User::factory()->create())->assignRole($role);
    }

    public function test_a_scoped_token_lists_only_what_it_reaches(): void
    {
        $names = $this->withToken($this->deployTokenFor($this->mine))
            ->getJson('/api/v1/packages')
            ->assertOk()
            ->json('data.*.name');

        // Its grant, plus the public repository everyone can read. Never the
        // private repository it was not given.
        $this->assertEqualsCanonicalizing(['mine/widgets', 'open/widgets'], $names);
    }

    public function test_a_scoped_token_cannot_see_another_repositorys_package(): void
    {
        $plain = $this->deployTokenFor($this->mine);

        $this->withToken($plain)->getJson("/api/v1/packages/{$this->theirPackage->id}")->assertNotFound();
        $this->withToken($plain)->postJson("/api/v1/packages/{$this->theirPackage->id}/sync")->assertNotFound();
        $this->withToken($plain)->deleteJson("/api/v1/packages/{$this->theirPackage->id}")->assertNotFound();

        $this->assertDatabaseHas('packages', ['id' => $this->theirPackage->id]);
    }

    public function test_a_scoped_token_cannot_see_another_repository(): void
    {
        $plain = $this->deployTokenFor($this->mine);

        $paths = $this->withToken($plain)->getJson('/api/v1/repositories')->assertOk()->json('data.*.path');

        // Its grant, plus the two public mounts: the named one and the seeded
        // default repository, whose null path is the registry root.
        $this->assertEqualsCanonicalizing([null, 'mine', 'open'], $paths);

        $this->withToken($plain)->getJson("/api/v1/repositories/{$this->theirs->id}")->assertNotFound();
    }

    /**
     * The distinction the upload endpoint has always drawn: a public
     * repository is readable by anyone, which grants nothing about changing
     * what is in it.
     */
    public function test_readable_is_not_writable(): void
    {
        $plain = $this->deployTokenFor($this->mine);

        $this->withToken($plain)->getJson("/api/v1/packages/{$this->openPackage->id}")->assertOk();

        $this->withToken($plain)->deleteJson("/api/v1/packages/{$this->openPackage->id}")->assertForbidden();
        $this->withToken($plain)->postJson("/api/v1/packages/{$this->openPackage->id}/sync")->assertForbidden();

        $this->withToken($plain)
            ->postJson('/api/v1/packages', [
                'url' => 'https://github.com/open/gadgets',
                'repository' => 'open',
                'webhook' => false,
                'sync' => false,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('packages', ['id' => $this->openPackage->id]);
    }

    /**
     * A sync error is written from the exception verbatim, so it carries the
     * provider's own words about somebody's repository — a URL, a host, a
     * refused credential. A public repository makes its packages readable by
     * every credential in the registry, and none of them was granted that.
     */
    public function test_a_sync_error_is_withheld_from_a_caller_that_only_reads_the_public_repository(): void
    {
        $reason = 'Failed to fetch https://git.internal.example/acme/widgets.git';

        $this->openPackage->forceFill(['sync_error' => $reason])->save();
        $this->myPackage->forceFill(['sync_error' => $reason])->save();

        $plain = $this->deployTokenFor($this->mine);

        // Reached through the public branch and no further: the field is absent
        // rather than empty, because "no error" is not what is being said.
        $this->withToken($plain)
            ->getJson("/api/v1/packages/{$this->openPackage->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.sync.error');

        // Both branches in one response, which is where a listing decides it
        // per row or not at all: mine/widgets first, open/widgets second.
        $this->withToken($plain)
            ->getJson('/api/v1/packages')
            ->assertOk()
            ->assertJsonPath('data.0.sync.error', $reason)
            ->assertJsonMissingPath('data.1.sync.error');

        // Its own repository, where the reason is the whole reason to ask.
        $this->withToken($plain)
            ->getJson("/api/v1/packages/{$this->myPackage->id}")
            ->assertOk()
            ->assertJsonPath('data.sync.error', $reason);
    }

    public function test_a_scoped_token_creates_syncs_and_deletes_inside_its_grant(): void
    {
        $plain = $this->deployTokenFor($this->mine);

        $created = $this->withToken($plain)
            ->postJson('/api/v1/packages', [
                'url' => 'https://github.com/mine/gadgets',
                'repository' => 'mine',
                'webhook' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('sync_queued', true)
            ->json('data.id');

        $this->withToken($plain)->postJson("/api/v1/packages/{$this->myPackage->id}/sync")->assertAccepted();
        $this->withToken($plain)->deleteJson("/api/v1/packages/{$created}")->assertNoContent();
    }

    /**
     * A grant on the package itself reaches that package without reaching the
     * repository around it — which is what a per-package deploy grant is for,
     * and exactly as far as it goes.
     */
    public function test_a_package_grant_reaches_that_package_and_no_further(): void
    {
        $principal = DeployToken::factory()->create();
        $principal->packages()->attach($this->theirPackage);

        $plain = Token::issue($principal, 'ci', [
            TokenAbility::ApiRead, TokenAbility::ApiWrite, TokenAbility::ApiDelete,
        ])->plainText;

        $this->withToken($plain)->postJson("/api/v1/packages/{$this->theirPackage->id}/sync")->assertAccepted();

        // The repository around it is visible — its package is — but creating a
        // second package there is a write to the repository, not to the grant.
        $this->withToken($plain)
            ->postJson('/api/v1/packages', [
                'url' => 'https://github.com/theirs/gadgets',
                'repository' => 'theirs',
                'webhook' => false,
                'sync' => false,
            ])
            ->assertForbidden();
    }

    /**
     * A personal access token sees exactly what its owner does, so a user's
     * grants are the API's scoping too.
     */
    public function test_a_users_token_sees_what_the_user_sees(): void
    {
        $user = User::factory()->create();
        $user->repositories()->attach($this->mine);

        $plain = Token::issue($user, 'laptop', [TokenAbility::ApiRead])->plainText;

        $names = $this->withToken($plain)->getJson('/api/v1/packages')->assertOk()->json('data.*.name');

        $this->assertEqualsCanonicalizing(['mine/widgets', 'open/widgets'], $names);

        $this->withToken($plain)->getJson("/api/v1/packages/{$this->myPackage->id}")->assertOk();
        $this->withToken($plain)->getJson("/api/v1/packages/{$this->theirPackage->id}")->assertNotFound();
    }

    /**
     * A grant says which packages are a person's to touch; their role says what
     * touching is allowed to mean. Both have to answer yes, or the ability
     * checkbox on their own token would be a way to do over HTTP what the panel
     * refuses them — which is the whole of the escalation this guards.
     */
    public function test_a_users_token_cannot_exceed_what_their_role_may_do(): void
    {
        // The shape of an external collaborator's account: it can read the
        // packages it was granted, and administer nothing.
        $user = $this->userWithRole(['ViewAny:Package', 'View:Package']);
        $user->repositories()->attach($this->mine);

        $plain = Token::issue($user, 'laptop', [
            TokenAbility::ApiRead, TokenAbility::ApiWrite, TokenAbility::ApiDelete,
        ])->plainText;

        $this->withToken($plain)->deleteJson("/api/v1/packages/{$this->myPackage->id}")->assertForbidden();

        $this->withToken($plain)
            ->postJson('/api/v1/packages', [
                'url' => 'https://github.com/mine/gadgets',
                'repository' => 'mine',
                'webhook' => false,
                'sync' => false,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('packages', ['id' => $this->myPackage->id]);

        // Syncing is not in the same family: the panel's sync action carries no
        // permission either, so a grant is the whole question there too.
        $this->withToken($plain)->postJson("/api/v1/packages/{$this->myPackage->id}/sync")->assertAccepted();
    }

    /**
     * And the same account, once its role says so.
     */
    public function test_a_users_token_deletes_and_creates_when_their_role_may(): void
    {
        $user = $this->userWithRole(['ViewAny:Package', 'View:Package', 'Create:Package', 'Delete:Package']);
        $user->repositories()->attach($this->mine);

        $plain = Token::issue($user, 'laptop', [
            TokenAbility::ApiRead, TokenAbility::ApiWrite, TokenAbility::ApiDelete,
        ])->plainText;

        $this->withToken($plain)
            ->postJson('/api/v1/packages', [
                'url' => 'https://github.com/mine/gadgets',
                'repository' => 'mine',
                'webhook' => false,
                'sync' => false,
            ])
            ->assertCreated();

        $this->withToken($plain)->deleteJson("/api/v1/packages/{$this->myPackage->id}")->assertNoContent();
    }

    /**
     * The permission is not a second grant: it says what this person may do,
     * never where. A role that can delete every package in the panel still
     * reaches only the ones its holder was given.
     */
    public function test_a_permission_does_not_widen_what_a_user_reaches(): void
    {
        $user = $this->userWithRole(['ViewAny:Package', 'View:Package', 'Delete:Package']);
        $user->repositories()->attach($this->mine);

        $plain = Token::issue($user, 'laptop', [TokenAbility::ApiRead, TokenAbility::ApiDelete])->plainText;

        // Invisible, so 404 rather than 403 — the answer never confirms it.
        $this->withToken($plain)->deleteJson("/api/v1/packages/{$this->theirPackage->id}")->assertNotFound();

        // Visible because the repository is public, which grants nothing about
        // changing what is in it.
        $this->withToken($plain)->deleteJson("/api/v1/packages/{$this->openPackage->id}")->assertForbidden();

        $this->assertDatabaseHas('packages', ['id' => $this->theirPackage->id]);
        $this->assertDatabaseHas('packages', ['id' => $this->openPackage->id]);
    }

    /**
     * No grants at all is the registry-wide credential the deploy token model
     * documents; the API does not quietly reinterpret that.
     */
    public function test_an_ungranted_deploy_token_reaches_everything(): void
    {
        $plain = Token::issue(DeployToken::factory()->create(), 'ci', [TokenAbility::ApiRead])->plainText;

        $this->withToken($plain)->getJson('/api/v1/packages')->assertOk()->assertJsonCount(3, 'data');

        // The three created here plus the seeded default repository.
        $this->withToken($plain)->getJson('/api/v1/repositories')->assertOk()->assertJsonCount(4, 'data');
    }
}
