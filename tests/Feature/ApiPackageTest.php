<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Jobs\SyncPackageJob;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * /api/v1/packages and /api/v1/repositories: what a CI pipeline and a
 * provisioning script actually do with them.
 */
class ApiPackageTest extends TestCase
{
    use RefreshDatabase;

    private string $plain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plain = Token::issue(
            User::factory()->superAdmin()->create(),
            'provisioning',
            [TokenAbility::ApiRead, TokenAbility::ApiWrite, TokenAbility::ApiDelete],
        )->plainText;
    }

    public function test_listing_packages_pages_and_filters(): void
    {
        $internal = Repository::factory()->create(['path' => 'internal']);

        // Every type is stated, because the factory picks one at random and
        // "plugin" is among them — the type filter below would otherwise pass
        // or fail depending on the seed.
        Package::factory()->create(['name' => 'acme/widgets', 'type' => 'library']);
        Package::factory()->create(['name' => 'acme/gadgets', 'type' => 'plugin']);
        Package::factory()->create(['name' => 'other/thing', 'type' => 'library', 'repository_id' => $internal->id]);

        $this->withToken($this->plain)->getJson('/api/v1/packages')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3)
            // Alphabetical, so paging through the registry is stable.
            ->assertJsonPath('data.0.name', 'acme/gadgets');

        $this->withToken($this->plain)->getJson('/api/v1/packages?q=acme/')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->withToken($this->plain)->getJson('/api/v1/packages?type=plugin')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'acme/gadgets');

        // How a script that knows only a name resolves it to an id.
        $this->withToken($this->plain)->getJson('/api/v1/packages?name=acme/widgets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.repository.path', null);

        $this->withToken($this->plain)->getJson('/api/v1/packages?repository=internal')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'other/thing');

        // Present but empty names the repository that has no path: the root.
        $this->withToken($this->plain)->getJson('/api/v1/packages?repository=')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_showing_a_package_lists_its_versions_newest_first(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets', 'latest_version' => '1.10.0']);

        foreach (['1.9.0', '1.10.0'] as $version) {
            $package->versions()->create([
                'version' => $version,
                'order' => str_pad($version, 20, '0', STR_PAD_LEFT),
                'reference' => str_repeat(substr($version, 2, 1), 40),
                'is_dev' => false,
                'shasum' => str_repeat('f', 40),
                'metadata' => ['name' => 'acme/widgets', 'version' => $version],
            ]);
        }

        $this->withToken($this->plain)->getJson("/api/v1/packages/{$package->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'acme/widgets')
            ->assertJsonPath('data.versions_count', 2)
            // Semantic order, not lexical: 1.10.0 is above 1.9.0.
            ->assertJsonPath('data.versions.0.version', '1.10.0')
            ->assertJsonPath('data.versions.1.version', '1.9.0')
            ->assertJsonPath('data.sync.running', false)
            // The whole composer.json belongs to /p2, not here.
            ->assertJsonMissingPath('data.versions.0.metadata');
    }

    public function test_an_unknown_package_is_a_404(): void
    {
        $this->withToken($this->plain)->getJson('/api/v1/packages/9999')->assertNotFound();
    }

    public function test_creating_a_package_registers_a_webhook_and_queues_the_first_sync(): void
    {
        Queue::fake([SyncPackageJob::class]);

        Http::fake([
            'api.github.com/repos/acme/widgets/hooks' => Http::response(['id' => 42], 201),
        ]);

        $response = $this->withToken($this->plain)
            ->postJson('/api/v1/packages', ['url' => 'https://github.com/acme/widgets'])
            ->assertCreated()
            // Guessed from the URL; the first sync replaces it with whatever
            // the repository's composer.json actually declares.
            ->assertJsonPath('data.name', 'acme/widgets')
            ->assertJsonPath('data.url', 'https://github.com/acme/widgets')
            ->assertJsonPath('data.repository.path', null)
            ->assertJsonPath('sync_queued', true)
            ->assertJsonPath('warnings', []);

        $package = Package::query()->sole();

        $this->assertSame($package->id, $response->json('data.id'));
        $this->assertSame('42', (string) $package->webhook_id);

        Queue::assertPushed(SyncPackageJob::class);
    }

    public function test_creating_a_package_can_skip_the_webhook_and_the_first_sync(): void
    {
        Queue::fake([SyncPackageJob::class]);

        $this->withToken($this->plain)
            ->postJson('/api/v1/packages', [
                'url' => 'https://github.com/acme/widgets',
                'name' => 'acme/renamed',
                'webhook' => false,
                'sync' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'acme/renamed')
            ->assertJsonPath('data.sync.webhook_enabled', false)
            ->assertJsonPath('sync_queued', false);

        Queue::assertNothingPushed();
    }

    public function test_creating_a_package_lands_in_the_named_repository(): void
    {
        Queue::fake([SyncPackageJob::class]);

        Repository::factory()->create(['path' => 'internal']);

        $this->withToken($this->plain)
            ->postJson('/api/v1/packages', [
                'url' => 'https://github.com/acme/widgets',
                'repository' => 'internal',
                'webhook' => false,
                'sync' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('data.repository.path', 'internal');
    }

    public function test_creating_a_package_validates_its_input(): void
    {
        Queue::fake([SyncPackageJob::class]);

        $this->withToken($this->plain)->postJson('/api/v1/packages', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['url']);

        $this->withToken($this->plain)
            ->postJson('/api/v1/packages', ['url' => 'https://github.com/acme/widgets', 'name' => 'Not A Name'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->withToken($this->plain)
            ->postJson('/api/v1/packages', ['url' => 'https://github.com/acme/widgets', 'repository' => 'nowhere'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['repository']);

        // A URL with no owner/repo in it cannot name a package on its own.
        $this->withToken($this->plain)
            ->postJson('/api/v1/packages', ['url' => 'https://example.com/', 'webhook' => false, 'sync' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->assertDatabaseCount('packages', 0);
    }

    public function test_a_name_or_url_already_served_by_the_repository_is_refused(): void
    {
        Queue::fake([SyncPackageJob::class]);

        Package::factory()->create([
            'name' => 'acme/widgets',
            'repository' => 'https://github.com/acme/widgets',
        ]);

        $this->withToken($this->plain)
            ->postJson('/api/v1/packages', ['url' => 'https://github.com/acme/widgets'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['url']);

        $this->withToken($this->plain)
            ->postJson('/api/v1/packages', [
                'url' => 'https://github.com/acme/widgets-fork',
                'name' => 'acme/widgets',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_triggering_a_sync_queues_one(): void
    {
        Queue::fake([SyncPackageJob::class]);

        $package = Package::factory()->create(['name' => 'acme/widgets']);

        $this->withToken($this->plain)->postJson("/api/v1/packages/{$package->id}/sync")
            ->assertAccepted()
            ->assertJsonPath('sync_queued', true)
            ->assertJsonPath('data.id', $package->id);

        Queue::assertPushed(SyncPackageJob::class, fn (SyncPackageJob $job): bool => ! $job->force);
    }

    public function test_a_forced_sync_is_a_rebuild(): void
    {
        Queue::fake([SyncPackageJob::class]);

        $package = Package::factory()->create(['name' => 'acme/widgets']);

        $this->withToken($this->plain)
            ->postJson("/api/v1/packages/{$package->id}/sync", ['force' => true])
            ->assertAccepted();

        Queue::assertPushed(SyncPackageJob::class, fn (SyncPackageJob $job): bool => $job->force);
    }

    /**
     * Not an error: the run already waiting will read the same refs this one
     * would have. Reported so a caller polling knows which run it is watching.
     */
    public function test_a_second_sync_while_one_is_pending_reports_that_it_queued_nothing(): void
    {
        Queue::fake([SyncPackageJob::class]);

        $package = Package::factory()->create(['name' => 'acme/widgets']);

        $this->withToken($this->plain)->postJson("/api/v1/packages/{$package->id}/sync")
            ->assertJsonPath('sync_queued', true);

        $this->withToken($this->plain)->postJson("/api/v1/packages/{$package->id}/sync")
            ->assertAccepted()
            ->assertJsonPath('sync_queued', false);
    }

    public function test_a_package_published_by_upload_has_nothing_to_sync(): void
    {
        Queue::fake([SyncPackageJob::class]);

        $package = Package::factory()->create(['name' => 'acme/widgets', 'repository' => null]);

        $this->withToken($this->plain)->postJson("/api/v1/packages/{$package->id}/sync")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['package']);

        Queue::assertNothingPushed();
    }

    public function test_deleting_a_package_removes_it(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        $this->withToken($this->plain)->deleteJson("/api/v1/packages/{$package->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('packages', ['id' => $package->id]);
    }

    public function test_repositories_are_listed_and_shown(): void
    {
        $internal = Repository::factory()->create(['path' => 'internal', 'name' => 'Internal']);

        Package::factory()->create(['repository_id' => $internal->id]);

        // The seeded default repository sorts first, and is the one whose path
        // is null because it answers at the registry root.
        $this->withToken($this->plain)->getJson('/api/v1/repositories')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.path', null)
            ->assertJsonPath('data.0.url', url('/'))
            ->assertJsonPath('data.1.name', 'Internal')
            ->assertJsonPath('data.1.packages_count', 1)
            ->assertJsonPath('data.1.url', url('/r/internal'));

        $this->withToken($this->plain)->getJson("/api/v1/repositories/{$internal->id}")
            ->assertOk()
            ->assertJsonPath('data.path', 'internal');

        $this->withToken($this->plain)->getJson('/api/v1/repositories/9999')->assertNotFound();
    }
}
