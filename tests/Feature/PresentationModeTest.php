<?php

namespace Tests\Feature;

use App\Livewire\PackageDeck;
use App\Models\Package;
use App\Models\Repository;
use App\Services\PackagePresentation;
use App\Support\Presentation\DeckMarkdown;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use ReflectionMethod;
use Tests\TestCase;
use WendellAdriel\SlideWire\Support\RemoteSessionManager;

/**
 * Presentation Mode: a package page replaced by a SlideWire deck.
 *
 * @see https://github.com/AlwaysCuriousCo/package-pipeline/issues/55
 */
class PresentationModeTest extends TestCase
{
    use RefreshDatabase;

    private const DECK = <<<'MD'
        ---
        theme: gaia
        marp: true
        ---

        # Widgets

        A deck about widgets.

        <!-- Remember to thank the sponsors. -->

        ---

        ## Setext heading
        stays one slide
        ---

        ```yaml
        ---
        not: a slide break
        ---
        ```

        <!-- slide -->

        ## Danger

        <script>alert('owned')</script>
        <b>bold markup</b> plain text
        MD;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    private function package(array $attributes = [], array $options = []): Package
    {
        Repository::default()->update(['public' => true]);

        $package = Package::factory()->create([
            'name' => 'acme/widgets',
            'repository' => 'https://github.com/acme/widgets',
            'description' => 'Widgets for Acme.',
            'latest_version' => 'v1.1.0',
            'page_enabled' => true,
            'page_presentation' => true,
            'page_presentation_options' => ['source' => 'custom', ...$options],
            'page_presentation_body' => self::DECK,
            ...$attributes,
        ]);

        $package->versions()->create([
            'version' => 'v1.1.0',
            'order' => '1.1.0.0',
            'reference' => str_repeat('b', 40),
            'is_dev' => false,
            'released_at' => '2026-02-01 12:00:00',
            'metadata' => ['name' => 'acme/widgets', 'version' => 'v1.1.0', 'license' => ['MIT']],
        ]);

        return $package;
    }

    public function test_the_splitter_reads_marp_slidev_and_slidewire_breaks(): void
    {
        $slides = (new DeckMarkdown)->split(self::DECK);

        $this->assertCount(3, $slides);
        $this->assertStringStartsWith('# Widgets', $slides[0]['body']);
        $this->assertSame(['Remember to thank the sponsors.'], $slides[0]['notes']);
        $this->assertStringNotContainsString('Remember', $slides[0]['body']);

        // A `---` under a line of text is that line's heading underline.
        $this->assertStringContainsString("stays one slide\n---", $slides[1]['body']);

        // A `---` inside a code fence is code.
        $this->assertStringContainsString('not: a slide break', $slides[1]['body']);
        $this->assertStringStartsWith('## Danger', $slides[2]['body']);
    }

    public function test_the_splitter_reads_per_slide_settings_and_drops_the_rest(): void
    {
        $slides = (new DeckMarkdown)->split(<<<'MD'
            # One

            ---
            transition: fade
            layout: center
            ---

            # Two

            ---

            <!-- _backgroundImage: url('art/bg.png') -->
            # Three

            ---

            ![bg](https://cdn.test/photo.jpg)
            # Four
            MD);

        $this->assertCount(4, $slides);
        $this->assertSame(['transition' => 'fade'], $slides[1]['meta']);
        $this->assertSame(['background' => 'art/bg.png'], $slides[2]['meta']);
        $this->assertSame([], $slides[2]['notes']);
        $this->assertSame(['background' => 'https://cdn.test/photo.jpg'], $slides[3]['meta']);
        $this->assertSame('# Four', $slides[3]['body']);
    }

    public function test_the_page_is_the_deck_with_notes_and_raw_html_left_out(): void
    {
        $this->package();

        $response = $this->get('/p/acme/widgets')->assertOk();

        $response->assertSee('A deck about widgets.')
            ->assertSee('rel="alternate" type="text/plain"', false)
            ->assertSee('<meta name="referrer" content="same-origin">', false)
            ->assertSee('<noscript>', false)
            // The install slide, generated from the package.
            ->assertSee('Latest release v1.1.0 · License MIT');

        $html = $response->getContent();

        $this->assertStringNotContainsString('thank the sponsors', $html);
        $this->assertStringNotContainsString("alert('owned')", $html);
        $this->assertStringNotContainsString('<b>bold markup</b>', $html);
        $this->assertStringContainsString('plain text', $html);
    }

    public function test_code_blocks_are_highlighted_and_escaped(): void
    {
        $this->package(['page_presentation_body' => "# Code\n\n```php\n<?php echo '<script>';\n```\n"]);

        $html = $this->get('/p/acme/widgets')->assertOk()->getContent();

        $this->assertStringContainsString('phiki', $html);
        $this->assertStringNotContainsString("echo '<script>'", $html);
    }

    public function test_a_deck_with_no_slides_falls_back_to_the_ordinary_page(): void
    {
        $this->package(['page_presentation_body' => "<!-- only a note -->\n"]);

        $this->get('/p/acme/widgets')
            ->assertOk()
            ->assertSee('Widgets for Acme.')
            ->assertDontSee('slidewire-shell', false);
    }

    public function test_presentation_mode_needs_the_page_switched_on(): void
    {
        $this->package(['page_enabled' => false]);

        $this->get('/p/acme/widgets')->assertNotFound();
        $this->get('/p/acme/widgets/slides.txt')->assertNotFound();
    }

    public function test_slides_txt_is_the_deck_as_text_without_its_notes(): void
    {
        $this->package();

        $response = $this->get('/p/acme/widgets/slides.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $text = $response->getContent();

        $this->assertStringContainsString("# Widgets\n\nA deck about widgets.", $text);
        $this->assertStringContainsString("\n---\n", $text);
        $this->assertStringContainsString('composer require acme/widgets', $text);
        $this->assertStringNotContainsString('thank the sponsors', $text);
    }

    public function test_slides_txt_only_exists_in_presentation_mode(): void
    {
        $this->package(['page_presentation' => false]);

        $this->get('/p/acme/widgets/slides.txt')->assertNotFound();
        $this->get('/p/acme/widgets')->assertOk()->assertDontSee('slides.txt');
    }

    public function test_the_sitemap_lists_the_text_copy(): void
    {
        $this->package();

        $this->get('/sitemap.xml')->assertOk()->assertSee('/p/acme/widgets/slides.txt');
    }

    public function test_the_panel_options_decide_the_theme_and_flags(): void
    {
        $package = $this->package(options: ['theme' => 'neon', 'show_controls' => false, 'transition' => 'bogus']);

        $deck = app(PackagePresentation::class)->compile($package);

        $this->assertSame('neon', $deck['deck_meta']['theme']);
        $this->assertSame('false', $deck['deck_meta']['show_controls']);
        $this->assertSame('slide', $deck['deck_meta']['transition']);

        $package->update(['page_presentation_options' => ['source' => 'custom', 'install_slide' => false]]);

        $this->assertCount(3, app(PackagePresentation::class)->compile($package->refresh())['slides']);
        $this->assertSame('always-curious', app(PackagePresentation::class)->compile($package)['deck_meta']['theme']);
    }

    public function test_a_slide_background_is_resolved_and_refused_when_unsafe(): void
    {
        $package = $this->package(['page_presentation_body' => <<<'MD'
            <!-- _backgroundImage: url(art/bg.png) -->
            # One

            ---

            <!-- _backgroundImage: url(javascript:alert(1)) -->
            # Two
            MD]);

        $slides = app(PackagePresentation::class)->compile($package)['slides'];

        $this->assertSame($package->pageImageBase().'/art/bg.png', $slides[0]->meta['background_image']);
        $this->assertArrayNotHasKey('background_image', $slides[1]->meta);
    }

    public function test_a_sync_reads_the_deck_file_from_the_repository(): void
    {
        Http::fake([
            'api.github.com/repos/acme/widgets/contents/PRESENTATION.md*' => Http::response('# From the repo'),
            'api.github.com/repos/acme/widgets/contents/*' => Http::response('Not Found', 404),
        ]);

        $package = $this->package(options: ['source' => 'repository']);

        $this->assertTrue(app(PackagePresentation::class)->refresh($package));

        $package->refresh();

        $this->assertSame('PRESENTATION.md', $package->page_presentation_source_path);
        $this->get('/p/acme/widgets')->assertOk()->assertSee('From the repo');
    }

    public function test_the_presenter_link_shows_notes_and_the_audience_link_does_not(): void
    {
        $package = $this->package();

        $session = app(RemoteSessionManager::class)->create(PackagePresentation::key($package), '1h');

        $signed = URL::temporarySignedRoute(
            'pages.package',
            now()->addHour(),
            ['vendor' => 'acme', 'package' => 'widgets', 'remote' => $session['key']],
            absolute: false,
        );

        $this->get($signed)
            ->assertOk()
            ->assertSee('Presenter view')
            ->assertSee('Remember to thank the sponsors.');

        $this->get('/p/acme/widgets?remote='.$session['key'])
            ->assertOk()
            ->assertDontSee('Presenter view')
            ->assertDontSee('thank the sponsors');

        // A forged signature is an audience member, not a presenter.
        $this->get($signed.'0')->assertOk()->assertDontSee('Presenter view');
    }

    public function test_a_presenter_stops_receiving_notes_once_the_session_ends(): void
    {
        $package = $this->package();
        $key = PackagePresentation::key($package);

        $session = app(RemoteSessionManager::class)->create($key, '1h');

        // A presenter's deck as it stands after a signed mount — the state a
        // tab left open, or a replayed snapshot, carries into later requests.
        $deck = new PackageDeck;
        $deck->presentation = $key;
        $deck->remoteMode = 'controller';
        $deck->remoteSessionKey = $session['key'];
        $deck->slides = app(PackagePresentation::class)->compile($package)['slides'];

        $presenter = (new ReflectionMethod($deck, 'presenter'))->invoke(...);

        $this->assertSame(['Remember to thank the sponsors.'], $presenter($deck)['notes']);

        app(RemoteSessionManager::class)->delete($session['key']);

        $this->assertNull($presenter($deck));
    }
}
