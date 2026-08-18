<?php

namespace Tests\Feature;

use App\Enums\PageBodySource;
use App\Enums\PageDownloads;
use App\Models\Package;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The public page for a package: who can see one, what it shows, and what it
 * refuses to hand over.
 *
 * Every assertion here is about an anonymous request — no session, no token —
 * because that is the only kind this surface answers.
 */
class PublicPackagePageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function package(array $attributes = [], bool $publicRepository = true): Package
    {
        Repository::default()->update(['public' => $publicRepository]);

        $package = Package::factory()->create([
            'name' => 'acme/widgets',
            'repository' => 'https://github.com/acme/widgets',
            'description' => 'Widgets for Acme.',
            'type' => 'library',
            'latest_version' => 'v1.1.0',
            'page_enabled' => true,
            ...$attributes,
        ]);

        $package->versions()->create([
            'version' => 'v1.1.0',
            'order' => '1.1.0.0',
            'reference' => str_repeat('b', 40),
            'is_dev' => false,
            'released_at' => '2026-02-01 12:00:00',
            'archive_path' => 'packages/acme/widgets/v110.zip',
            'shasum' => sha1('zip'),
            'metadata' => ['name' => 'acme/widgets', 'version' => 'v1.1.0'],
        ]);

        $package->versions()->create([
            'version' => 'v1.0.0',
            'order' => '1.0.0.0',
            'reference' => str_repeat('a', 40),
            'is_dev' => false,
            'released_at' => '2026-01-01 12:00:00',
            'archive_path' => 'packages/acme/widgets/v100.zip',
            'shasum' => sha1('older'),
            'metadata' => ['name' => 'acme/widgets', 'version' => 'v1.0.0'],
        ]);

        return $package;
    }

    /**
     * A dist disk with the two archives on it, so a download that should
     * succeed can.
     */
    private function fakeArchives(): void
    {
        $disk = Storage::fake('dists');

        config(['filesystems.dists' => 'dists']);

        $disk->put('packages/acme/widgets/v110.zip', 'zip-bytes');
        $disk->put('packages/acme/widgets/v100.zip', 'older-bytes');
    }

    public function test_a_package_without_a_page_is_not_found(): void
    {
        $this->package(['page_enabled' => false]);

        $this->get('/p/acme/widgets')->assertNotFound();
    }

    public function test_an_enabled_page_describes_the_package_to_anyone(): void
    {
        $this->package();

        $response = $this->get('/p/acme/widgets');

        $response->assertOk();
        $response->assertSee('acme/widgets');
        $response->assertSee('Widgets for Acme.');
        $response->assertSee('v1.1.0');
    }

    public function test_a_page_is_reached_however_the_name_was_typed(): void
    {
        $this->package();

        $this->get('/p/ACME/Widgets')->assertOk();
    }

    public function test_a_page_carries_the_tags_a_social_platform_and_a_crawler_read(): void
    {
        $this->package([
            'page_image' => 'https://cdn.example.com/card.png',
            'page_source' => true,
        ]);

        $response = $this->get('/p/acme/widgets');

        $response->assertSee('<link rel="canonical" href="'.config('app.url').'/p/acme/widgets">', false);
        $response->assertSee('<meta property="og:title" content="acme/widgets — '.config('app.name').'">', false);
        $response->assertSee('<meta property="og:url" content="'.config('app.url').'/p/acme/widgets">', false);
        $response->assertSee('<meta property="og:image" content="https://cdn.example.com/card.png">', false);
        // The large card only when there is an image to fill it.
        $response->assertSee('<meta name="twitter:card" content="summary_large_image">', false);
        $response->assertSee('"@type":"SoftwareSourceCode"', false);
        $response->assertSee('"codeRepository":"https://github.com/acme/widgets"', false);
        $response->assertSee('"softwareVersion":"v1.1.0"', false);
    }

    public function test_a_page_without_an_image_asks_for_the_text_card(): void
    {
        $this->package();

        $this->get('/p/acme/widgets')
            ->assertSee('<meta name="twitter:card" content="summary">', false)
            ->assertDontSee('og:image', false);
    }

    public function test_the_registry_wide_image_stands_in_when_a_package_sets_none(): void
    {
        config(['registry.page_image' => '/images/icon-512.png']);

        $this->package();

        $this->get('/p/acme/widgets')
            ->assertSee('<meta property="og:image" content="'.config('app.url').'/images/icon-512.png">', false);
    }

    public function test_install_commands_are_shown_when_the_switch_is_on(): void
    {
        $this->package(['page_install' => true]);

        $this->get('/p/acme/widgets')
            ->assertSee('composer require acme/widgets:^1.1.0');
    }

    public function test_install_commands_are_withheld_when_the_switch_is_off(): void
    {
        $this->package(['page_install' => false]);

        $this->get('/p/acme/widgets')
            ->assertOk()
            ->assertDontSee('composer require acme/widgets');
    }

    public function test_a_private_repository_publishes_the_page_but_withholds_everything_needing_a_token(): void
    {
        $this->package([
            'page_install' => true,
            'page_downloads' => PageDownloads::All,
        ], publicRepository: false);

        $response = $this->get('/p/acme/widgets');

        $response->assertOk();
        // The page still describes the package — that is the point of one.
        $response->assertSee('Widgets for Acme.');
        $response->assertSee('Access required');
        // Neither of the two things that would need a credential.
        $response->assertDontSee('composer require acme/widgets');
        $response->assertDontSee('/p/acme/widgets/download');
    }

    public function test_a_private_package_hands_out_no_archive_however_it_was_configured(): void
    {
        $this->fakeArchives();
        $this->package(['page_downloads' => PageDownloads::All], publicRepository: false);

        $this->get('/p/acme/widgets/download')->assertNotFound();
        $this->get('/p/acme/widgets/download/v1.1.0')->assertNotFound();
    }

    public function test_downloads_are_off_by_default(): void
    {
        $this->fakeArchives();
        $this->package();

        $this->get('/p/acme/widgets')->assertDontSee('/p/acme/widgets/download');
        $this->get('/p/acme/widgets/download')->assertNotFound();
    }

    public function test_the_latest_setting_offers_the_current_release_alone(): void
    {
        $this->fakeArchives();
        $this->package(['page_downloads' => PageDownloads::Latest]);

        $this->get('/p/acme/widgets')->assertSee('Download v1.1.0');

        $this->get('/p/acme/widgets/download')
            ->assertOk()
            ->assertDownload('widgets-v1.1.0.zip');

        // The history is not reachable by editing the URL.
        $this->get('/p/acme/widgets/download/v1.0.0')->assertNotFound();
    }

    public function test_every_version_is_downloadable_when_that_is_what_was_chosen(): void
    {
        $this->fakeArchives();
        $this->package(['page_downloads' => PageDownloads::All]);

        $this->get('/p/acme/widgets/download/v1.0.0')
            ->assertOk()
            ->assertDownload('widgets-v1.0.0.zip');
    }

    public function test_a_download_from_a_page_is_counted_like_any_other(): void
    {
        $this->fakeArchives();
        $package = $this->package(['page_downloads' => PageDownloads::Latest]);

        $this->get('/p/acme/widgets/download')->assertOk();

        $this->assertSame(1, $package->fresh()->total_downloads);
        $this->assertDatabaseHas('downloads', [
            'package_id' => $package->id,
            'version' => 'v1.1.0',
        ]);
    }

    public function test_a_head_request_does_not_count_as_a_download(): void
    {
        $this->fakeArchives();
        $package = $this->package(['page_downloads' => PageDownloads::Latest]);

        $this->head('/p/acme/widgets/download')->assertOk();

        $this->assertSame(0, $package->fresh()->total_downloads);
    }

    public function test_the_version_history_is_shown_or_withheld_on_its_own_switch(): void
    {
        $this->package(['page_versions' => false]);

        $this->get('/p/acme/widgets')->assertDontSee('Versions');

        Package::query()->update(['page_versions' => true]);

        $this->get('/p/acme/widgets')
            ->assertSee('Versions')
            ->assertSee('v1.0.0');
    }

    public function test_the_repository_readme_is_rendered_and_its_html_is_escaped(): void
    {
        $this->package([
            'page_source_path' => 'README.md',
            'page_source_body' => "# Widgets\n\nSee the [docs](docs/install.md).\n\n<script>alert('xss')</script>\n\n![logo](art/logo.png)\n",
        ]);

        $response = $this->get('/p/acme/widgets');

        $response->assertSee('<h1>Widgets</h1>', false);
        // Raw HTML in somebody else's README never becomes HTML here.
        $response->assertDontSee('<script>alert', false);
        // Relative links resolve against the repository they were written in.
        $response->assertSee('https://github.com/acme/widgets/blob/HEAD/docs/install.md', false);
        // Images come back through this registry rather than from the
        // provider's raw host, which answers 404 to a reader with no
        // credential for a private repository.
        $response->assertSee(config('app.url').'/p/acme/widgets/asset/art/logo.png', false);
        $response->assertDontSee('raw.githubusercontent.com', false);
    }

    public function test_a_monorepo_readme_resolves_its_links_against_the_right_directory(): void
    {
        $this->package([
            'subdirectory' => 'packages/widgets',
            'page_source_path' => 'packages/widgets/README.md',
            'page_source_body' => "See the [guide](docs/install.md) and the [licence](/LICENSE.md).\n\n![logo](/art/logo.png)\n",
        ]);

        $response = $this->get('/p/acme/widgets');

        // A plain relative link is relative to the package's own directory.
        $response->assertSee('https://github.com/acme/widgets/blob/HEAD/packages/widgets/docs/install.md', false);
        // A leading slash means the repository root, as it does on the
        // provider that rendered this README first.
        $response->assertSee('https://github.com/acme/widgets/blob/HEAD/LICENSE.md', false);
        // A root-relative image is the asset route with no subdirectory in
        // the path; the path travels repository-root-relative either way.
        $response->assertSee(config('app.url').'/p/acme/widgets/asset/art/logo.png', false);
        $response->assertDontSee('packages/widgets/LICENSE.md', false);
        $response->assertDontSee('asset/packages/widgets/art/logo.png', false);
    }

    public function test_content_written_in_the_panel_is_published_when_it_is_chosen(): void
    {
        $this->package([
            'page_body_source' => PageBodySource::Custom,
            'page_body' => '# Written here',
            // Still stored from an earlier sync, and deliberately not shown:
            // which source publishes is stated on the record, not inferred
            // from which fields happen to be filled.
            'page_source_path' => 'README.md',
            'page_source_body' => '# From the repository',
        ]);

        $this->get('/p/acme/widgets')
            ->assertSee('<h1>Written here</h1>', false)
            ->assertDontSee('From the repository');
    }

    public function test_a_monorepo_package_resolves_links_against_its_own_directory(): void
    {
        $this->package([
            'subdirectory' => 'packages/widgets',
            'page_source_path' => 'README.md',
            'page_source_body' => '![logo](art/logo.png)',
        ]);

        $this->get('/p/acme/widgets')
            ->assertSee(config('app.url').'/p/acme/widgets/asset/packages/widgets/art/logo.png', false);
    }

    public function test_a_page_is_scoped_to_the_repository_it_is_served_from(): void
    {
        $internal = Repository::create(['name' => 'Internal', 'path' => 'internal', 'public' => true]);

        $this->package();

        Package::factory()->create([
            'repository_id' => $internal->id,
            'name' => 'acme/widgets',
            'repository' => 'https://github.com/other/widgets',
            'description' => 'A different package with the same name.',
            'page_enabled' => true,
        ]);

        $this->get('/p/acme/widgets')->assertSee('Widgets for Acme.');

        $this->get('/r/internal/p/acme/widgets')
            ->assertOk()
            ->assertSee('A different package with the same name.')
            ->assertSee('<link rel="canonical" href="'.config('app.url').'/r/internal/p/acme/widgets">', false);
    }

    public function test_a_page_in_a_repository_that_does_not_exist_is_not_found(): void
    {
        $this->package();

        $this->get('/r/nowhere/p/acme/widgets')->assertNotFound();
    }

    public function test_the_type_and_source_lines_are_each_on_their_own_switch(): void
    {
        $this->package(['page_type' => true, 'page_source' => true]);

        $this->get('/p/acme/widgets')
            ->assertSee('Type:')
            ->assertSee('https://github.com/acme/widgets');

        Package::query()->update(['page_type' => false, 'page_source' => false]);

        $this->get('/p/acme/widgets')
            ->assertOk()
            ->assertDontSee('Type:')
            // Including out of the structured data, which is read by more
            // things than the page is.
            ->assertDontSee('https://github.com/acme/widgets');
    }

    public function test_the_source_repository_is_withheld_by_default(): void
    {
        $this->package();

        $this->get('/p/acme/widgets')
            ->assertOk()
            ->assertDontSee('https://github.com/acme/widgets');
    }

    public function test_an_image_from_the_repository_is_served_through_the_registry(): void
    {
        $this->package();

        Http::fake([
            'api.github.com/repos/acme/widgets/contents/art/logo.png*' => Http::response('png-bytes'),
        ]);

        $response = $this->get('/p/acme/widgets/asset/art/logo.png');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        // Pinned to being an image: an SVG committed to a repository would
        // otherwise be a document running at this origin.
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('png-bytes', $response->getContent());
    }

    public function test_an_image_is_read_at_the_ref_the_page_describes(): void
    {
        $this->package();

        Http::fake([
            'api.github.com/repos/acme/widgets/contents/*' => Http::response('png-bytes'),
        ]);

        $this->get('/p/acme/widgets/asset/art/logo.png')->assertOk();

        // The release's ref, not a ref the caller chose and not the default
        // branch: a page and the screenshots in it are one commit's.
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'ref='.str_repeat('b', 40)));
    }

    public function test_a_second_request_for_an_image_costs_the_provider_nothing(): void
    {
        $this->package();

        Http::fake([
            'api.github.com/repos/acme/widgets/contents/*' => Http::response('png-bytes'),
        ]);

        $this->get('/p/acme/widgets/asset/art/logo.png')->assertOk();
        $this->get('/p/acme/widgets/asset/art/logo.png')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_a_missing_image_is_not_re_fetched_on_every_visit(): void
    {
        $this->package();

        Http::fake([
            'api.github.com/repos/acme/widgets/contents/*' => Http::response('Not Found', 404),
        ]);

        $this->get('/p/acme/widgets/asset/art/moved.png')->assertNotFound();
        $this->get('/p/acme/widgets/asset/art/moved.png')->assertNotFound();

        Http::assertSentCount(1);
    }

    public function test_the_asset_route_serves_images_and_nothing_else(): void
    {
        $this->package();

        Http::fake(['api.github.com/*' => Http::response('secrets')]);

        // Not a way to read a private repository's source, its .env.example
        // or its CI configuration.
        $this->get('/p/acme/widgets/asset/composer.json')->assertNotFound();
        $this->get('/p/acme/widgets/asset/.env.example')->assertNotFound();
        $this->get('/p/acme/widgets/asset/src/Widget.php')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_the_asset_route_cannot_climb_out_of_the_repository(): void
    {
        $this->package();

        Http::fake(['api.github.com/*' => Http::response('bytes')]);

        $this->get('/p/acme/widgets/asset/../../other/logo.png')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_the_asset_route_is_closed_for_a_package_with_no_page(): void
    {
        $this->package(['page_enabled' => false]);

        Http::fake(['api.github.com/*' => Http::response('bytes')]);

        $this->get('/p/acme/widgets/asset/art/logo.png')->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_an_oversized_image_is_refused_rather_than_cached(): void
    {
        config(['registry.pages.max_asset_kilobytes' => 1]);

        $this->package();

        Http::fake([
            'api.github.com/repos/acme/widgets/contents/*' => Http::response(str_repeat('x', 4096)),
        ]);

        $this->get('/p/acme/widgets/asset/art/huge.png')->assertNotFound();
    }

    public function test_a_card_image_living_in_the_repository_is_served_through_the_registry(): void
    {
        // The case a private repository makes unavoidable: Slack and X fetch
        // an og:image anonymously from their own infrastructure, so a raw
        // provider URL answers them 404 and the link goes out with no card.
        $this->package(['page_image' => 'art/social-card.png']);

        $this->get('/p/acme/widgets')
            ->assertSee('<meta property="og:image" content="'.config('app.url').'/p/acme/widgets/asset/art/social-card.png">', false);
    }

    public function test_a_card_image_may_still_be_an_absolute_url(): void
    {
        $this->package(['page_image' => 'https://cdn.example.com/card.png']);

        $this->get('/p/acme/widgets')
            ->assertSee('<meta property="og:image" content="https://cdn.example.com/card.png">', false);
    }
}
