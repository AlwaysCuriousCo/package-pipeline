<?php

namespace Tests\Feature;

use App\Enums\SourceProvider;
use App\Jobs\SyncPackageJob;
use App\Models\Package;
use App\Models\Source;
use App\Services\GitHub\GitHubSourceClient;
use App\Services\GitHub\WebhookRegistrar;
use App\Services\GitLab\GitLabSourceClient;
use App\Services\PackageSynchronizer;
use App\Sources\UnsupportedProviderException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The provider abstraction: GitLab speaks the same RepositoryClient and
 * SourceClient contracts GitHub does, and undeclared providers refuse loudly.
 */
class GitLabProviderTest extends TestCase
{
    use RefreshDatabase;

    private function makeGitLabPackage(): Package
    {
        return Package::factory()->unreleased()->create([
            'name' => 'group/widgets-placeholder',
            'repository' => 'https://gitlab.com/group/widgets',
            'token' => 'glpat-secret',
        ]);
    }

    private function fakeGitLab(): void
    {
        Storage::fake(config('filesystems.dists'));

        Http::fake([
            'gitlab.com/api/v4/projects/group%2Fwidgets/repository/tags*' => Http::response([
                ['name' => 'v1.0.0', 'commit' => ['id' => str_repeat('a', 40)]],
            ]),
            'gitlab.com/api/v4/projects/group%2Fwidgets/repository/branches*' => Http::response([
                ['name' => 'main', 'commit' => ['id' => str_repeat('b', 40)]],
            ]),
            'gitlab.com/api/v4/projects/group%2Fwidgets/repository/files/composer.json/raw*' => Http::response(
                json_encode(['name' => 'acme/widgets', 'description' => 'Widgets.', 'type' => 'library']),
            ),
            'gitlab.com/api/v4/projects/group%2Fwidgets/repository/commits/*' => Http::response([
                'committed_date' => '2026-02-01T12:00:00Z',
            ]),
            'gitlab.com/api/v4/projects/group%2Fwidgets/repository/archive.zip*' => fn () => Http::response(
                'zip-bytes', 200, ['Content-Type' => 'application/zip'],
            ),
            'gitlab.com/api/v4/projects/group%2Fwidgets' => Http::response(['default_branch' => 'main']),
        ]);
    }

    public function test_a_gitlab_url_resolves_the_gitlab_client_from_the_host(): void
    {
        $package = $this->makeGitLabPackage();

        $this->assertSame(SourceProvider::Gitlab, $package->provider());
        $this->assertSame('group/widgets', $package->repositoryPath());
        $this->assertSame('https://gitlab.com/api/v4', $package->apiUrl());
    }

    public function test_a_gitlab_package_syncs_end_to_end(): void
    {
        $this->fakeGitLab();

        $package = $this->makeGitLabPackage();

        app(PackageSynchronizer::class)->sync($package);

        $package->refresh();

        $this->assertSame('acme/widgets', $package->name);
        $this->assertSame('1.0.0', $package->latest_version);
        $this->assertSame(
            ['1.0.0', 'dev-main'],
            $package->versions()->pluck('version')->sort()->values()->all(),
        );

        // The archive came from GitLab's archive endpoint, stored like any other.
        $this->assertSame(0, $package->versions()->whereNull('archive_path')->count());

        // Every call carried the token in GitLab's own header.
        Http::assertSent(fn ($request): bool => $request->hasHeader('PRIVATE-TOKEN', 'glpat-secret'));
    }

    public function test_gitlab_pagination_follows_the_next_page_header(): void
    {
        Storage::fake(config('filesystems.dists'));

        Http::fakeSequence('gitlab.com/api/v4/projects/group%2Fwidgets/repository/tags*')
            ->push([['name' => 'v1.0.0', 'commit' => ['id' => str_repeat('a', 40)]]], 200, ['X-Next-Page' => '2'])
            ->push([['name' => 'v1.1.0', 'commit' => ['id' => str_repeat('b', 40)]]]);

        $package = $this->makeGitLabPackage();

        $tags = $package->client()->tags();

        $this->assertSame(['v1.0.0', 'v1.1.0'], array_keys($tags));
    }

    public function test_the_gitlab_fallback_never_leaks_the_github_token(): void
    {
        config(['services.github.token' => 'ghp_environment']);

        $package = Package::factory()->create(['repository' => 'https://gitlab.com/group/other', 'token' => null]);

        $this->assertNull($package->accessToken());
    }

    public function test_registering_a_webhook_uses_the_gitlab_api_and_endpoint(): void
    {
        Http::fake([
            'gitlab.com/api/v4/projects/group%2Fwidgets/hooks' => Http::response(['id' => 77], 201),
        ]);

        $package = $this->makeGitLabPackage();

        app(WebhookRegistrar::class)->register($package);

        $package->refresh();

        $this->assertSame(77, $package->webhook_id);
        $this->assertNotNull($package->webhook_secret);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/hooks')
            && $request['url'] === route('webhooks.gitlab.package', $package)
            && $request['token'] === $package->webhook_secret);
    }

    public function test_a_gitlab_delivery_with_the_right_token_queues_a_sync(): void
    {
        Queue::fake();

        $package = $this->makeGitLabPackage();
        $package->forceFill(['webhook_id' => 77, 'webhook_secret' => 'hook-secret'])->save();

        $this->postJson(route('webhooks.gitlab.package', $package), ['ref' => 'refs/tags/v1.0.0'], [
            'X-Gitlab-Token' => 'hook-secret',
            'X-Gitlab-Event' => 'Tag Push Hook',
        ])->assertAccepted();

        $this->assertNotNull($package->fresh()->webhook_received_at);
        Queue::assertPushed(SyncPackageJob::class);
    }

    public function test_a_gitlab_delivery_with_the_wrong_token_is_rejected(): void
    {
        Queue::fake();

        $package = $this->makeGitLabPackage();
        $package->forceFill(['webhook_secret' => 'hook-secret'])->save();

        $this->postJson(route('webhooks.gitlab.package', $package), [], [
            'X-Gitlab-Token' => 'wrong',
            'X-Gitlab-Event' => 'Push Hook',
        ])->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    public function test_gitlab_sources_browse_projects(): void
    {
        Http::fake([
            'gitlab.com/api/v4/projects*' => Http::response([
                ['id' => 7, 'path_with_namespace' => 'group/widgets', 'web_url' => 'https://gitlab.com/group/widgets'],
            ]),
        ]);

        $source = Source::factory()->withToken('glpat-secret')->create([
            'provider' => SourceProvider::Gitlab,
        ]);

        $client = $source->client();

        $this->assertInstanceOf(GitLabSourceClient::class, $client);

        $projects = $client->projects('widg');

        $this->assertCount(1, $projects);
        $this->assertSame('group/widgets', $projects[0]->fullName);
        $this->assertSame('7', $projects[0]->id);
    }

    public function test_github_sources_browse_projects_with_client_side_search(): void
    {
        Http::fake([
            'api.github.com/app/installations/*/access_tokens' => Http::response([
                'token' => 'ghs_installation',
                'expires_at' => now()->addHour()->toIso8601String(),
            ]),
            'api.github.com/installation/repositories*' => Http::response([
                'repositories' => [
                    ['id' => 1, 'full_name' => 'acme/widgets', 'html_url' => 'https://github.com/acme/widgets'],
                    ['id' => 2, 'full_name' => 'acme/gadgets', 'html_url' => 'https://github.com/acme/gadgets'],
                ],
            ]),
        ]);

        $this->configureGitHubApp();

        $source = Source::factory()->create(['account' => 'acme']);

        $client = $source->client();

        $this->assertInstanceOf(GitHubSourceClient::class, $client);

        $projects = $client->projects('widg');

        $this->assertCount(1, $projects);
        $this->assertSame('acme/widgets', $projects[0]->fullName);
    }

    public function test_undeclared_providers_refuse_loudly(): void
    {
        $package = Package::factory()->create(['repository' => 'https://gitea.example.com/acme/widgets']);

        $this->assertSame(SourceProvider::Gitea, $package->provider());

        $this->expectException(UnsupportedProviderException::class);

        $package->client()->tags();
    }

    private function configureGitHubApp(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        config()->set('services.github.app', [
            'id' => '123456',
            'slug' => 'acme-pipeline',
            'private_key' => $pem,
            'api_url' => 'https://api.github.com',
        ]);
    }
}
