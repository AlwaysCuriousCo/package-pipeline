<?php

namespace App\Services;

use App\Models\Package;
use App\Models\Repository;
use App\Support\PageMarkdown;
use App\Support\Presentation\DeckMarkdown;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;
use WendellAdriel\SlideWire\DTOs\Slide;
use WendellAdriel\SlideWire\Enums\SlideTransition;
use WendellAdriel\SlideWire\Enums\SlideTransitionSpeed;
use WendellAdriel\SlideWire\Support\CodeHighlighter;

/**
 * A package's Presentation Mode deck: where its markdown comes from and what
 * SlideWire is handed to show.
 *
 * SlideWire reads its decks from files on disk. This app's decks are not
 * files: they are a column, read from the repository at sync time like a
 * page body, or pasted into the panel. So nothing is written to disk at all —
 * App\Support\Presentation\PackageDeckCompiler answers SlideWire's compiler
 * from here, and the compiled slides live in the cache. A git deploy finds a
 * clean tree, a replaced app folder or a fresh container finds nothing
 * missing, and the database stays the only thing that has to survive.
 *
 * The markdown is untrusted for the reason a README is, and is held to a
 * stricter rule than one: raw HTML is stripped rather than sanitized, and
 * SlideWire's own markdown renderer — which lets HTML through — is never
 * given it. Each slide is rendered by PageMarkdown instead, with SlideWire's
 * highlighter used only for the code blocks, which it escapes.
 *
 * @see https://github.com/AlwaysCuriousCo/package-pipeline/issues/55
 */
class PackagePresentation
{
    /**
     * Every slide's HTML travels in the deck component's Livewire state, and
     * that state is posted back on every slide change — so a deck is bounded
     * by count as well as by the page body's byte limit.
     */
    public const MAX_SLIDES = 200;

    private const KEY_PATTERN = '/^package-(\d+)-(\d+)$/';

    public function __construct(
        private readonly PackagePage $pages = new PackagePage,
        private readonly PageMarkdown $markdown = new PageMarkdown,
        private readonly DeckMarkdown $decks = new DeckMarkdown,
    ) {}

    /**
     * The name SlideWire knows this package's deck by, as served from the
     * repository the request resolved — which decides where its relative
     * images point, so it is part of the name.
     */
    public static function key(Package $package): string
    {
        return 'package-'.$package->getKey().'-'.$package->servingRepository()->getKey();
    }

    /**
     * The package a deck name addresses, served from the repository it
     * names — or null when it names no deck that is published.
     */
    public static function packageFor(string $key): ?Package
    {
        if (preg_match(self::KEY_PATTERN, $key, $match) !== 1) {
            return null;
        }

        $repository = Repository::query()->find((int) $match[2]);
        $package = $repository?->packages()->whereKey((int) $match[1])->first();

        if (! $package instanceof Package) {
            return null;
        }

        $package = $package->servedFrom($repository);

        return $package->hasPresentation() ? $package : null;
    }

    public static function isKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * Read the deck file out of the repository and store it. Never throws,
     * for the reason PackagePage::refresh() does not: this runs inside a
     * sync, and a deck that could not be read is no reason to fail one.
     *
     * @param  string|null  $ref  the ref to read at, or null for the page's
     */
    public function refresh(Package $package, ?string $ref = null): bool
    {
        $candidates = $package->presentationCandidates();

        if ($candidates === []) {
            return false;
        }

        try {
            $client = $package->client();
            $ref ??= $package->pageRef();

            foreach ($candidates as $path) {
                $body = $client->file($path, $ref, (string) $package->subdirectory);

                if ($body === null || trim($body) === '') {
                    continue;
                }

                $package->recordBookkeeping([
                    'page_presentation_source_body' => $this->pages->bounded($body),
                    'page_presentation_source_path' => $path,
                    'page_presentation_synced_at' => now(),
                ]);

                return true;
            }

            // Recorded as a result, so the next sync does not walk the whole
            // list again against a repository that has none of them.
            $package->recordBookkeeping([
                'page_presentation_source_body' => null,
                'page_presentation_source_path' => null,
                'page_presentation_synced_at' => now(),
            ]);

            return false;
        } catch (Throwable $exception) {
            Log::warning('Could not read the presentation deck for a package.', [
                'package' => $package->name,
                'exception' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The deck as SlideWire wants it, plus each slide's speaker notes — kept
     * apart from the slides so that nothing a visitor's browser is sent can
     * contain them. Null when there is no deck to show, which the page reads
     * as "render the ordinary page instead".
     *
     * @return array{deck_meta: array<string, string>, slides: list<Slide>, notes: list<list<string>>}|null
     */
    public function compile(Package $package): ?array
    {
        $slides = $this->slides($package);

        if ($slides === []) {
            return null;
        }

        $options = $package->presentationOptions();
        $key = self::key($package);

        $build = function () use ($slides, $options, $package, $key): array {
            $theme = $this->theme($options['theme']);

            return [
                'deck_meta' => $this->deckMeta($options, $theme),
                'slides' => array_map(fn (array $slide, int $index): array => (new Slide(
                    id: "{$key}-{$index}",
                    html: $this->render($slide['body'], $package, $theme),
                    meta: $this->slideMeta($slide['meta'], $package),
                    h: $index,
                ))->toArray(), $slides, array_keys($slides)),
                'notes' => array_column($slides, 'notes'),
            ];
        };

        $minutes = (int) config('registry.pages.markdown_cache_minutes');

        // Keyed on everything the result is made of — the slides, the options
        // and the bases relative URLs resolve against — so that any change to
        // any of them is a new entry rather than a stale one.
        $compiled = $minutes > 0
            ? Cache::remember('presentation:'.hash('xxh128', serialize([
                $key, $slides, $options, $package->pageImageBase(), $package->pageImageRootBase(),
                $package->pageLinkBase(), $package->pageLinkRootBase(),
            ])), now()->addMinutes($minutes), $build)
            : $build();

        return [
            'deck_meta' => $compiled['deck_meta'],
            'slides' => array_map(Slide::fromArray(...), $compiled['slides']),
            'notes' => $compiled['notes'],
        ];
    }

    /**
     * The deck as plain markdown, one slide after another: what slides.txt
     * serves. Speaker notes and directives are already gone by this point.
     */
    public function transcript(Package $package): ?string
    {
        $slides = $this->slides($package);

        return $slides === [] ? null : implode("\n\n---\n\n", array_column($slides, 'body'))."\n";
    }

    /**
     * The deck's slides, split but not rendered, with the install slide on
     * the end when it is switched on.
     *
     * @return list<array{meta: array<string, string>, body: string, notes: list<string>}>
     */
    private function slides(Package $package): array
    {
        $markdown = $package->presentationMarkdown();

        if ($markdown === null) {
            return [];
        }

        $slides = array_slice($this->decks->split($this->pages->bounded($markdown)), 0, self::MAX_SLIDES);

        if ($slides !== [] && $package->presentationOptions()['install_slide'] && ($install = $this->installSlide($package)) !== null) {
            $slides[] = ['meta' => [], 'body' => $install, 'notes' => []];
        }

        return $slides;
    }

    /**
     * The last slide: how to install the package, generated from the same
     * facts the ordinary page prints — because the deck has replaced it.
     */
    private function installSlide(Package $package): ?string
    {
        $lines = ['## '.$package->name];

        if (filled($package->description)) {
            $lines[] = (string) $package->description;
        }

        if ($package->pageShowsInstall()) {
            $lines[] = "```shell\n".implode("\n", $package->installCommands())."\n```";
        } elseif ($package->pageRequiresAccess()) {
            // The same notice the ordinary page shows a private package's
            // visitor where the commands would be.
            $notice = array_filter([
                trim((string) config('registry.pages.access_notice.heading')),
                trim((string) config('registry.pages.access_notice.body')),
            ]);

            if ($notice !== []) {
                $lines[] = '**'.array_shift($notice).'**'.($notice === [] ? '' : ' — '.implode(' ', $notice));
            }
        }

        $facts = [];

        if ($package->latest_version !== null) {
            $facts[] = 'Latest release '.$package->latest_version;

            $license = $package->versions()->where('version', $package->latest_version)->value('license');

            if (filled($license)) {
                $facts[] = 'License '.$license;
            }
        }

        if ($facts !== []) {
            $lines[] = implode(' · ', $facts);
        }

        return count($lines) > 1 ? implode("\n\n", $lines) : null;
    }

    /**
     * One slide's HTML: the markdown through the page renderer with raw HTML
     * stripped, and its code blocks through SlideWire's highlighter.
     */
    private function render(string $body, Package $package, string $theme): string
    {
        $token = 'swfence'.bin2hex(random_bytes(6)).'x';

        [$markdown, $blocks] = $this->decks->extractFences($body, $token);

        $html = $this->markdown->render(
            $markdown,
            $package->pageLinkBase(),
            $package->pageImageBase(),
            $package->pageLinkRootBase(),
            $package->pageImageRootBase(),
            allowHtml: false,
        );

        $highlighter = app(CodeHighlighter::class);

        $html = (string) preg_replace_callback('#<p>'.$token.'(\d+)</p>#', function (array $match) use ($blocks, $highlighter, $theme): string {
            $block = $blocks[(int) $match[1]] ?? null;

            return $block === null ? '' : $highlighter->highlight($block['code'], $block['language'], presentationTheme: $theme)->toHtml();
        }, $html);

        return '<div class="slidewire-markdown">'.$html.'</div>';
    }

    /**
     * The deck-wide settings, from the panel and nowhere else, validated
     * against what SlideWire accepts: a stored value that has stopped being
     * one falls back to the default rather than reaching the view.
     *
     * The booleans are spelled the two ways SlideWire's view reads them —
     * the show_* flags compare against the string 'false', the pause flag is
     * cast to bool.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, string>
     */
    private function deckMeta(array $options, string $theme): array
    {
        $flag = fn (string $key): string => $options[$key] ? 'true' : 'false';

        return [
            'theme' => $theme,
            'transition' => in_array($options['transition'], SlideTransition::values(), true) ? $options['transition'] : 'slide',
            'transition_speed' => in_array($options['transition_speed'], SlideTransitionSpeed::values(), true) ? $options['transition_speed'] : 'default',
            'transition_duration' => (string) max(0, min(5000, (int) $options['transition_duration'])),
            'auto_slide' => (string) max(0, min(600000, (int) $options['auto_slide'])),
            'auto_slide_pause_on_interaction' => $options['auto_slide_pause_on_interaction'] ? '1' : '0',
            'show_controls' => $flag('show_controls'),
            'show_progress' => $flag('show_progress'),
            'show_fullscreen_button' => $flag('show_fullscreen_button'),
        ];
    }

    private function theme(mixed $theme): string
    {
        return is_string($theme) && array_key_exists($theme, (array) config('slidewire.themes')) ? $theme : 'always-curious';
    }

    /**
     * The per-slide settings a deck is allowed: a transition SlideWire knows,
     * and a background image — resolved the way a slide's own images are, and
     * refused unless it ends up as a plain absolute URL, since SlideWire puts
     * it inside a style attribute.
     *
     * @param  array<string, string>  $meta
     * @return array<string, string>
     */
    private function slideMeta(array $meta, Package $package): array
    {
        $safe = [];

        if (in_array($meta['transition'] ?? null, SlideTransition::values(), true)) {
            $safe['transition'] = $meta['transition'];
        }

        $background = trim($meta['background'] ?? '');

        if ($background !== '' && ! preg_match('#^(https?:)?//#i', $background)) {
            $base = str_starts_with($background, '/') ? $package->pageImageRootBase() : $package->pageImageBase();
            $background = $base === null ? '' : rtrim($base, '/').'/'.implode('/', array_map(rawurlencode(...), explode('/', ltrim($background, './'))));
        }

        if (preg_match('#^https?://[^\s()\'"\\\\;<>]+$#i', $background) === 1) {
            $safe['background_image'] = $background;
        }

        return $safe;
    }
}
