<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Services\PackageSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PackageSyncTest extends TestCase
{
    use RefreshDatabase;

    private function fakeGitHub(): void
    {
        $composerJson = [
            'name' => 'acme/widgets',
            'description' => 'Widgets for Acme.',
            'type' => 'library',
            'require' => ['php' => '^8.3'],
        ];

        Http::fake([
            'api.github.com/repos/acme/widgets/tags*' => Http::response([
                ['name' => 'v1.0.0', 'commit' => ['sha' => str_repeat('a', 40)]],
                ['name' => 'v1.1.0', 'commit' => ['sha' => str_repeat('b', 40)]],
                ['name' => 'not-a-version', 'commit' => ['sha' => str_repeat('c', 40)]],
            ]),
            'api.github.com/repos/acme/widgets/branches*' => Http::response([
                ['name' => 'main', 'commit' => ['sha' => str_repeat('d', 40)]],
                ['name' => '2.x', 'commit' => ['sha' => str_repeat('e', 40)]],
            ]),
            'api.github.com/repos/acme/widgets/contents/composer.json*' => Http::response($composerJson),
        ]);
    }

    private function makePackage(): Package
    {
        return Package::factory()->unreleased()->create([
            'name' => 'acme/widgets-placeholder',
            'repository' => 'https://github.com/acme/widgets',
            'token' => 'ghp_secret',
        ]);
    }

    public function test_sync_stores_tag_and_branch_versions(): void
    {
        $this->fakeGitHub();

        $package = $this->makePackage();

        app(PackageSynchronizer::class)->sync($package);

        $package->refresh();

        $this->assertSame('acme/widgets', $package->name);
        $this->assertSame('v1.1.0', $package->latest_version);
        $this->assertSame('Widgets for Acme.', $package->description);
        $this->assertSame('library', $package->type);
        $this->assertNotNull($package->last_synced_at);
        $this->assertNull($package->sync_error);

        $versions = $package->versions()->pluck('is_dev', 'version');

        $this->assertSame([
            '2.x-dev' => true,
            'dev-main' => true,
            'v1.0.0' => false,
            'v1.1.0' => false,
        ], $versions->sortKeys()->all());

        // The malformed tag is ignored rather than served.
        $this->assertNull($package->versions()->where('version', 'not-a-version')->first());
    }

    public function test_sync_removes_versions_that_no_longer_exist(): void
    {
        $this->fakeGitHub();

        $package = $this->makePackage();
        $package->versions()->create([
            'version' => 'v0.9.0',
            'reference' => str_repeat('f', 40),
            'is_dev' => false,
            'metadata' => ['name' => 'acme/widgets', 'version' => 'v0.9.0'],
        ]);

        app(PackageSynchronizer::class)->sync($package);

        $this->assertNull($package->versions()->where('version', 'v0.9.0')->first());
    }

    public function test_sync_authenticates_with_the_package_token(): void
    {
        $this->fakeGitHub();

        app(PackageSynchronizer::class)->sync($this->makePackage());

        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer ghp_secret'));
    }

    public function test_a_failed_sync_records_the_error_on_the_package(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

        $package = $this->makePackage();

        try {
            app(PackageSynchronizer::class)->sync($package);
            $this->fail('Expected the sync to throw.');
        } catch (RequestException) {
            // Expected.
        }

        $this->assertNotNull($package->refresh()->sync_error);
    }

    public function test_the_sync_command_syncs_a_package_by_name(): void
    {
        $this->fakeGitHub();

        $package = $this->makePackage();

        $this->artisan('packages:sync', ['name' => $package->name])
            ->assertSuccessful();

        $this->assertSame(4, $package->versions()->count());
    }
}
