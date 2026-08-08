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
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The operational console commands: everything the panel does, scriptable.
 */
class OperationalCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_add_creates_the_account_with_roles_and_prints_a_reset_link(): void
    {
        Role::findOrCreate('developer', 'web');

        $this->artisan('user:add', [
            'email' => 'dev@example.com',
            '--name' => 'Dev Example',
            '--role' => ['developer'],
        ])
            ->expectsOutputToContain('single-use link')
            ->assertSuccessful();

        $user = User::query()->where('email', 'dev@example.com')->sole();

        $this->assertSame('Dev Example', $user->name);
        $this->assertTrue($user->hasRole('developer'));
    }

    public function test_user_add_rejects_unknown_roles_and_duplicate_emails(): void
    {
        $this->artisan('user:add', ['email' => 'dev@example.com', '--role' => ['nope']])
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'dev@example.com']);

        User::factory()->create(['email' => 'taken@example.com']);

        $this->artisan('user:add', ['email' => 'taken@example.com'])->assertFailed();
    }

    public function test_user_reset_password_prints_a_link_for_a_known_account(): void
    {
        User::factory()->create(['email' => 'dev@example.com']);

        $this->artisan('user:reset-password', ['email' => 'dev@example.com'])
            ->expectsOutputToContain('single-use link')
            ->assertSuccessful();

        $this->artisan('user:reset-password', ['email' => 'nobody@example.com'])->assertFailed();
    }

    public function test_package_add_creates_the_package_and_queues_its_first_sync(): void
    {
        Queue::fake();

        $this->artisan('package:add', [
            'repository' => 'https://github.com/acme/widgets',
            '--no-webhook' => true,
        ])->assertSuccessful();

        $package = Package::query()->where('name', 'acme/widgets')->sole();

        $this->assertTrue($package->composerRepository->isDefault());

        Queue::assertPushed(SyncPackageJob::class);
    }

    public function test_package_add_targets_a_named_composer_repository(): void
    {
        Queue::fake();
        Repository::factory()->create(['path' => 'internal']);

        $this->artisan('package:add', [
            'repository' => 'https://github.com/acme/widgets',
            '--repo' => 'internal',
            '--no-webhook' => true,
            '--no-sync' => true,
        ])->assertSuccessful();

        $this->assertSame(
            'internal',
            Package::query()->where('name', 'acme/widgets')->sole()->composerRepository->path,
        );

        $this->artisan('package:add', [
            'repository' => 'https://github.com/acme/other',
            '--repo' => 'missing',
        ])->assertFailed();
    }

    public function test_package_add_refuses_a_duplicate(): void
    {
        Package::factory()->create(['name' => 'acme/widgets', 'repository' => 'https://github.com/acme/widgets']);

        $this->artisan('package:add', [
            'repository' => 'https://github.com/acme/widgets',
            '--no-webhook' => true,
        ])->assertFailed();
    }

    public function test_package_delete_removes_rows_and_stored_archives(): void
    {
        config(['filesystems.dists' => 's3']);
        Storage::fake('s3');
        Storage::disk('s3')->put('packages/acme/widgets/v100.zip', 'zip-bytes');

        $package = Package::factory()->create(['name' => 'acme/widgets']);
        $package->versions()->create([
            'version' => 'v1.0.0',
            'reference' => str_repeat('a', 40),
            'is_dev' => false,
            'archive_path' => 'packages/acme/widgets/v100.zip',
            'shasum' => sha1('zip-bytes'),
            'metadata' => ['name' => 'acme/widgets', 'version' => 'v1.0.0'],
        ]);

        $this->artisan('package:delete', ['name' => 'acme/widgets', '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('packages', ['name' => 'acme/widgets']);
        Storage::disk('s3')->assertMissing('packages/acme/widgets/v100.zip');
    }

    public function test_package_delete_demands_a_repo_when_the_name_is_served_twice(): void
    {
        $internal = Repository::factory()->create(['path' => 'internal']);
        Package::factory()->create(['name' => 'acme/widgets']);
        Package::factory()->create(['name' => 'acme/widgets', 'repository_id' => $internal->id]);

        $this->artisan('package:delete', ['name' => 'acme/widgets', '--force' => true])->assertFailed();

        $this->artisan('package:delete', ['name' => 'acme/widgets', '--repo' => 'internal', '--force' => true])
            ->assertSuccessful();

        $this->assertDatabaseCount('packages', 1);
    }

    public function test_token_add_issues_for_a_user_and_prints_the_plain_text(): void
    {
        User::factory()->create(['email' => 'dev@example.com']);

        $this->artisan('token:add', ['name' => 'laptop', '--user' => 'dev@example.com'])
            ->expectsOutputToContain('pp_')
            ->assertSuccessful();

        $token = Token::query()->sole();

        $this->assertSame(User::class, $token->tokenable_type);
        $this->assertSame([TokenAbility::RepositoryRead->value], $token->abilities);
    }

    public function test_token_add_creates_a_deploy_token_on_demand(): void
    {
        $this->artisan('token:add', [
            'name' => 'ci',
            '--deploy' => 'production',
            '--ability' => ['read', 'write'],
            '--expires-days' => '30',
        ])->assertSuccessful();

        $deployToken = DeployToken::query()->where('name', 'production')->sole();
        $token = $deployToken->token;

        $this->assertNotNull($token);
        $this->assertSame(
            [TokenAbility::RepositoryRead->value, TokenAbility::RepositoryWrite->value],
            $token->abilities,
        );
        $this->assertNotNull($token->expires_at);
    }

    public function test_token_add_demands_exactly_one_owner(): void
    {
        $this->artisan('token:add', ['name' => 'nobody'])->assertFailed();

        $this->artisan('token:add', ['name' => 'both', '--user' => 'a@b.c', '--deploy' => 'x'])->assertFailed();
    }

    public function test_token_revoke_by_prefix(): void
    {
        $token = Token::factory()->create();

        $this->artisan('token:revoke', ['prefix' => $token->token_prefix])->assertSuccessful();

        $this->assertSoftDeleted('access_tokens', ['id' => $token->id]);

        $this->artisan('token:revoke', ['prefix' => $token->token_prefix])->assertFailed();
    }
}
