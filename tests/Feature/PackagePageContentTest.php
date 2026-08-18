<?php

namespace Tests\Feature;

use App\Jobs\RefreshPackagePage;
use App\Models\Package;
use App\Services\PackagePage;
use App\Services\PackageSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Zipball;
use Tests\TestCase;

/**
 * Where a package page's body comes from: which file wins, when it is read,
 * and what a registry with no pages is made to spend on it.
 */
class PackagePageContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.dists'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fakeGitHub(array $overrides = []): void
    {
        Http::fake($overrides + [
            'api.github.com/repos/acme/widgets/tags*' => Http::response([
                ['name' => 'v1.0.0', 'commit' => ['sha' => str_repeat('a', 40)]],
            ]),
            'api.github.com/repos/acme/widgets/branches*' => Http::response([]),
            'api.github.com/repos/acme/widgets/contents/composer.json*' => Http::response([
                'name' => 'acme/widgets',
                'description' => 'Widgets for Acme.',
                'type' => 'library',
            ]),
            'api.github.com/repos/acme/widgets/commits/*' => Http::response([
                'commit' => ['committer' => ['date' => '2026-02-01T12:00:00Z']],
            ]),
            'api.github.com/repos/acme/widgets/zipball/*' => fn () => Http::response(Zipball::bytes(), 200, [
                'Content-Type' => 'application/zip',
            ]),
            // Everything else the page reader might ask for.
            'api.github.com/repos/acme/widgets/contents/*' => Http::response('Not Found', 404),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function package(array $attributes = []): Package
    {
        return Package::factory()->unreleased()->create([
            'name' => 'acme/widgets-placeholder',
            'repository' => 'https://github.com/acme/widgets',
            'token' => 'ghp_secret',
            ...$attributes,
        ]);
    }

    public function test_a_sync_reads_the_page_file_for_a_package_that_publishes_one(): void
    {
        $this->fakeGitHub([
            'api.github.com/repos/acme/widgets/contents/package-page.md*' => Http::response('# Widgets'),
        ]);

        $package = $this->package(['page_enabled' => true]);

        app(PackageSynchronizer::class)->sync($package);

        $package->refresh();

        $this->assertSame('package-page.md', $package->page_source_path);
        $this->assertSame('# Widgets', $package->page_source_body);
        $this->assertNotNull($package->page_source_synced_at);
    }

    public function test_the_readme_is_the_fallback(): void
    {
        $this->fakeGitHub([
            'api.github.com/repos/acme/widgets/contents/package-page.md*' => Http::response('Not Found', 404),
            'api.github.com/repos/acme/widgets/contents/README.md*' => Http::response('# From the readme'),
        ]);

        $package = $this->package(['page_enabled' => true]);

        app(PackageSynchronizer::class)->sync($package);

        $this->assertSame('README.md', $package->fresh()->page_source_path);
    }

    public function test_a_package_with_no_page_costs_the_provider_nothing(): void
    {
        $this->fakeGitHub([
            'api.github.com/repos/acme/widgets/contents/package-page.md*' => Http::response('# Widgets'),
        ]);

        $package = $this->package(['page_enabled' => false]);

        app(PackageSynchronizer::class)->sync($package);

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'contents/package-page.md'));
        $this->assertNull($package->fresh()->page_source_body);
    }

    public function test_a_repository_with_no_page_file_records_that_it_was_looked_for(): void
    {
        $this->fakeGitHub();

        $package = $this->package(['page_enabled' => true]);

        app(PackageSynchronizer::class)->sync($package);

        $package->refresh();

        // Recorded, so the next sync does not walk the whole candidate list
        // again against a repository that has none of them.
        $this->assertNull($package->page_source_path);
        $this->assertNotNull($package->page_source_synced_at);
    }

    public function test_an_unreadable_repository_does_not_fail_the_sync(): void
    {
        $this->fakeGitHub([
            'api.github.com/repos/acme/widgets/contents/package-page.md*' => Http::response('Server error', 500),
            'api.github.com/repos/acme/widgets/contents/README.md*' => Http::response('Server error', 500),
        ]);

        $package = $this->package(['page_enabled' => true]);

        // A README that could not be read is not a reason to fail the sync
        // that published the release.
        app(PackageSynchronizer::class)->sync($package);

        $package->refresh();

        $this->assertNull($package->sync_error);
        $this->assertSame('1.0.0', $package->latest_version);
    }

    public function test_switching_a_page_on_queues_a_read_rather_than_waiting_for_the_next_sync(): void
    {
        Bus::fake();

        $package = $this->package();

        Bus::assertNotDispatched(RefreshPackagePage::class);

        $package->update(['page_enabled' => true]);

        Bus::assertDispatched(RefreshPackagePage::class);
    }

    public function test_a_page_already_read_is_not_re_read_on_every_edit(): void
    {
        Bus::fake();

        $package = $this->package([
            'page_enabled' => false,
            'page_source_path' => 'README.md',
            'page_source_body' => '# Widgets',
            'page_source_synced_at' => now(),
        ]);

        $package->update(['page_enabled' => true]);

        Bus::assertNotDispatched(RefreshPackagePage::class);
    }

    public function test_a_body_past_the_ceiling_is_cut_and_says_so(): void
    {
        config(['registry.pages.max_body_kilobytes' => 1]);

        $this->fakeGitHub([
            'api.github.com/repos/acme/widgets/contents/package-page.md*' => Http::response(
                str_repeat("A line of a very long document.\n", 200),
            ),
        ]);

        $package = $this->package(['page_enabled' => true]);

        app(PackageSynchronizer::class)->sync($package);

        $body = (string) $package->fresh()->page_source_body;

        $this->assertLessThan(2048, strlen($body));
        $this->assertStringContainsString('This document was truncated.', $body);
    }

    public function test_a_package_published_by_upload_has_nothing_to_read(): void
    {
        $package = Package::factory()->create([
            'name' => 'acme/uploaded',
            'repository' => null,
            'page_enabled' => true,
        ]);

        $this->assertFalse(app(PackagePage::class)->refresh($package));
    }
}
