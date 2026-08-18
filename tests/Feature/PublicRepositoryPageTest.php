<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A repository's landing page, and the two documents that get the pages
 * found — served at the same URLs the Composer API answers on, which is the
 * whole reason the feature is shaped this way.
 */
class PublicRepositoryPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_root_still_lands_on_the_panel_when_no_page_is_published(): void
    {
        $this->get('/')->assertRedirect('/admin/login');
    }

    public function test_the_composer_api_is_untouched_by_the_page_at_its_root(): void
    {
        Repository::default()->update(['public' => true, 'page_enabled' => true]);

        // The page answers at "/" and packages.json answers where it always
        // did; a browser and Composer never see each other's response.
        $this->get('/')->assertOk()->assertSee('<!DOCTYPE html>', false);
        $this->get('/packages.json')->assertOk()->assertJsonStructure(['metadata-url']);
    }

    public function test_an_enabled_root_page_describes_the_repository(): void
    {
        Repository::default()->update([
            'public' => true,
            'page_enabled' => true,
            'description' => 'Everything Acme publishes.',
            'page_body' => '## Getting started',
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Everything Acme publishes.');
        $response->assertSee('<h2>Getting started</h2>', false);
        $response->assertSee('composer config repositories.');
        $response->assertSee('"@type":"CollectionPage"', false);
    }

    public function test_only_packages_with_pages_of_their_own_are_listed(): void
    {
        Repository::default()->update(['public' => true, 'page_enabled' => true]);

        Package::factory()->create(['name' => 'acme/listed', 'page_enabled' => true]);
        Package::factory()->create(['name' => 'acme/unlisted', 'page_enabled' => false]);

        $this->get('/')
            ->assertSee('acme/listed')
            // A package this registry will not describe is not named either:
            // that is how a private package's existence leaks out of a public
            // landing page.
            ->assertDontSee('acme/unlisted');
    }

    public function test_the_package_list_can_be_switched_off(): void
    {
        Repository::default()->update([
            'public' => true,
            'page_enabled' => true,
            'page_lists_packages' => false,
        ]);

        Package::factory()->create(['name' => 'acme/listed', 'page_enabled' => true]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('acme/listed');
    }

    public function test_a_named_repository_answers_its_page_at_its_own_mount(): void
    {
        $internal = Repository::create([
            'name' => 'Internal',
            'path' => 'internal',
            'public' => true,
            'page_enabled' => true,
            'description' => 'Internal packages.',
        ]);

        $this->get('/r/internal')
            ->assertOk()
            ->assertSee('Internal packages.')
            ->assertSee('<link rel="canonical" href="'.config('app.url').'/r/internal">', false);

        $this->assertSame($internal->id, Repository::forPath('internal')?->id);
    }

    public function test_a_named_repository_without_a_page_is_not_found(): void
    {
        Repository::create(['name' => 'Internal', 'path' => 'internal', 'public' => true]);

        // Not a redirect to a login form: this URL has never answered a page,
        // and a stranger who found it has no account to log into.
        $this->get('/r/internal')->assertNotFound();
    }

    public function test_the_sitemap_lists_every_published_page(): void
    {
        Repository::default()->update(['public' => true, 'page_enabled' => true]);

        Package::factory()->create(['name' => 'acme/listed', 'page_enabled' => true]);
        Package::factory()->create(['name' => 'acme/unlisted', 'page_enabled' => false]);

        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml');
        $response->assertSee('<loc>'.config('app.url').'</loc>', false);
        $response->assertSee('<loc>'.config('app.url').'/p/acme/listed</loc>', false);
        $response->assertDontSee('acme/unlisted');
    }

    public function test_robots_points_at_the_sitemap_and_keeps_crawlers_off_the_api(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertOk();
        $response->assertSee('Sitemap: '.config('app.url').'/sitemap.xml');
        $response->assertSee('Disallow: /admin');
        $response->assertSee('Disallow: /dist/');
    }

    public function test_an_installation_that_opts_out_of_indexing_says_so(): void
    {
        config(['registry.pages.sitemap' => false]);

        Repository::default()->update(['public' => true, 'page_enabled' => true]);

        // Both documents still answer: a 404 on robots.txt is read by some
        // crawlers as permission to crawl everything.
        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee("User-agent: *\nDisallow: /");

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertDontSee('<loc>', false);
    }
}
