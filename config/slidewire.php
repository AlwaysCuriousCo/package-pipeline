<?php

declare(strict_types=1);

use Phiki\Theme\Theme;
use WendellAdriel\SlideWire\DTOs\FontConfig;
use WendellAdriel\SlideWire\DTOs\HighlightConfig;
use WendellAdriel\SlideWire\DTOs\RemoteConfig;
use WendellAdriel\SlideWire\DTOs\SlidesConfig;
use WendellAdriel\SlideWire\DTOs\ThemeConfig;
use WendellAdriel\SlideWire\DTOs\ThemeFont;
use WendellAdriel\SlideWire\Enums\FontSource;
use WendellAdriel\SlideWire\Enums\SlideTransition;
use WendellAdriel\SlideWire\Enums\SlideTransitionSpeed;

return [

    /*
    |--------------------------------------------------------------------------
    | Presentation Roots
    |--------------------------------------------------------------------------
    |
    | Here you may configure the directories where SlideWire should look for
    | Blade and Markdown presentations. These paths are used by the compiler,
    | route macro, and scaffold command when resolving presentation files.
    |
    */

    // Package decks are not files: they are read from the database by
    // App\Support\Presentation\PackageDeckCompiler, so nothing here is
    // ever written to and a deploy never finds a dirty tree. The root stays
    // for decks a maintainer might add to the codebase by hand.
    'presentation_roots' => [
        resource_path('views/pages/slides'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Slide Defaults
    |--------------------------------------------------------------------------
    |
    | These values provide the fallback runtime settings for every deck and
    | slide. Slide attributes take priority over deck attributes, and deck
    | attributes take priority over the values defined here.
    |
    */

    'slides' => new SlidesConfig(
        theme: 'always-curious',
        showControls: true,
        showProgress: true,
        showFullscreenButton: true,
        keyboard: true,
        touch: true,
        transition: SlideTransition::Slide,
        transitionDuration: 350,
        transitionSpeed: SlideTransitionSpeed::Default,
        autoSlide: 0,
        autoSlidePauseOnInteraction: true,
        highlight: new HighlightConfig(
            enabled: true,
            theme: Theme::CatppuccinMocha,
            font: 'JetBrains Mono',
            fontSize: 'text-base',
        ),
    ),

    /*
    |--------------------------------------------------------------------------
    | Theme Presets
    |--------------------------------------------------------------------------
    |
    | Each theme preset defines the background classes, syntax highlight theme,
    | and typography applied when a deck or slide uses that theme name. The
    | config uses DTOs so the structure stays explicit and strongly typed.
    |
    */

    'themes' => [
        // The default: the Always Curious brand kit — Void ground, Bone text,
        // Sofia Sans Extra Condensed headings over Noto Sans, the fonts set
        // by the `ac-deck` class in resources/css/app.css. Dark, which is the
        // one kind of custom theme SlideWire's controls are drawn for.
        // @see https://brandkit.alwayscurious.app/b/alwayscurious
        'always-curious' => new ThemeConfig(
            background: 'ac-deck bg-gradient-to-br from-[#05363D] via-[#08434C] to-[#073036] text-[#CECEC6]',
            highlightTheme: Theme::GithubDark,
            title: new ThemeFont(font: 'ac-display', color: 'text-[#F2FCFD]', size: 'text-5xl'),
            text: new ThemeFont(font: 'ac-body', color: 'text-[#CECEC6]', size: 'text-lg'),
        ),
        'default' => new ThemeConfig(
            background: 'bg-gradient-to-br from-slate-900 via-blue-950 to-slate-950 text-slate-50',
            highlightTheme: Theme::CatppuccinMocha,
            title: new ThemeFont(font: 'Inter', color: 'text-slate-50', size: 'text-4xl'),
            text: new ThemeFont(font: 'Inter', color: 'text-slate-200', size: 'text-lg'),
        ),
        'black' => new ThemeConfig(
            background: 'bg-slate-900 text-slate-200',
            highlightTheme: Theme::CatppuccinMocha,
            title: new ThemeFont(font: 'Inter', color: 'text-slate-200', size: 'text-4xl'),
            text: new ThemeFont(font: 'Inter', color: 'text-slate-300', size: 'text-lg'),
        ),
        'white' => new ThemeConfig(
            background: 'bg-white text-zinc-800',
            highlightTheme: Theme::CatppuccinLatte,
            title: new ThemeFont(font: 'Inter', color: 'text-zinc-800', size: 'text-4xl'),
            text: new ThemeFont(font: 'Inter', color: 'text-zinc-600', size: 'text-lg'),
        ),
        'aurora' => new ThemeConfig(
            background: 'bg-gradient-to-br from-emerald-950 via-cyan-900 to-slate-950 text-emerald-50',
            highlightTheme: Theme::CatppuccinMocha,
            title: new ThemeFont(font: 'Inter', color: 'text-emerald-50', size: 'text-4xl'),
            text: new ThemeFont(font: 'Inter', color: 'text-cyan-100', size: 'text-lg'),
        ),
        'sunset' => new ThemeConfig(
            background: 'bg-gradient-to-br from-rose-950 via-orange-900 to-amber-700 text-orange-50',
            highlightTheme: Theme::CatppuccinMocha,
            title: new ThemeFont(font: 'Inter', color: 'text-orange-50', size: 'text-4xl'),
            text: new ThemeFont(font: 'Inter', color: 'text-amber-100', size: 'text-lg'),
        ),
        'neon' => new ThemeConfig(
            background: 'bg-gradient-to-br from-fuchsia-950 via-violet-900 to-cyan-900 text-fuchsia-50',
            highlightTheme: Theme::CatppuccinMocha,
            title: new ThemeFont(font: 'Inter', color: 'text-fuchsia-50', size: 'text-4xl'),
            text: new ThemeFont(font: 'Inter', color: 'text-cyan-100', size: 'text-lg'),
        ),
        'solarized' => new ThemeConfig(
            background: 'bg-yellow-50 text-slate-600',
            highlightTheme: Theme::CatppuccinLatte,
            title: new ThemeFont(font: 'Inter', color: 'text-slate-700', size: 'text-4xl'),
            text: new ThemeFont(font: 'Inter', color: 'text-slate-600', size: 'text-lg'),
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Font Loading
    |--------------------------------------------------------------------------
    |
    | Map font family names used by theme typography and code blocks to their
    | loading strategy. System fonts require no loading, while Google Fonts
    | families are automatically included in the rendered presentation.
    |
    */

    // System, as far as SlideWire knows: every family is served from Bunny
    // by the Vite build (see vite.config.js) rather than from Google, which
    // would hand every visitor's address to a third party.
    'fonts' => [
        'Inter' => new FontConfig(source: FontSource::System),
        'JetBrains Mono' => new FontConfig(source: FontSource::System),
    ],

    /*
    |--------------------------------------------------------------------------
    | Remote Presenter Control
    |--------------------------------------------------------------------------
    |
    | Settings for the optional remote control feature. A deck only enters
    | remote mode when a `remote` query parameter is present; otherwise these
    | values are unused and the deck renders exactly as a solo presentation.
    | Pass `cacheStore` to the DTO to isolate remote state on a specific cache
    | store (e.g. 'redis'); it defaults to the application's default store.
    |
    */

    // Every viewer polls this store, so on an installation whose default
    // cache is the database, point SLIDEWIRE_REMOTE_CACHE_STORE at redis.
    'remote' => new RemoteConfig(
        ttl: '2h',
        pollInterval: '2s',
        viewerControls: false,
        cacheStore: env('SLIDEWIRE_REMOTE_CACHE_STORE'),
    ),
];
