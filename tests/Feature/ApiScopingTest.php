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
use Tests\TestCase;

/**
 * The API's access control, which is not its own: every query runs through the
 * same visibleTo() scope the Composer endpoints and the panel narrow by, and
 * every mutation through the same grant rules the artifact upload obeys.
 *
 * Two rules, kept apart on purpose. Whether a caller may *see* a package
 * decides 404 or not — a 403 would confirm the name to a token that could
 * never have fetched it. Whether it may *change* one is asked afterwards, and
 * being able to read a public repository answers it with no.
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

        $plain = Token::issue($user, 'laptop', [TokenAbility::ApiRead, TokenAbility::ApiDelete])->plainText;

        $names = $this->withToken($plain)->getJson('/api/v1/packages')->assertOk()->json('data.*.name');

        $this->assertEqualsCanonicalizing(['mine/widgets', 'open/widgets'], $names);

        $this->withToken($plain)->deleteJson("/api/v1/packages/{$this->theirPackage->id}")->assertNotFound();
        $this->withToken($plain)->deleteJson("/api/v1/packages/{$this->myPackage->id}")->assertNoContent();
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
