@props([
    // The repository the page belongs to — its name badges the header and its
    // URL is where the header links home.
    'repository',
    'title',
    'summary',
    'canonical',
    'image' => null,
    'ogType' => 'website',
    // The JSON-LD document, already an array, or null. Built in the view data
    // rather than here so its shape can be asserted in a test.
    'schema' => null,
])

{{--
    The shell every public page renders into.

    Two jobs: the page itself, and everything a machine reads about the page.
    The second half is not decoration — a registry's packages are found
    through search and shared through links, and a link with no card beside it
    is a link most people do not click. So the Open Graph and Twitter tags,
    the canonical URL and the JSON-LD block are part of the page rather than a
    later addition, and each one is filled from facts this app already holds.

    Deliberately independent of the Filament panel's own layout: this is
    served to anonymous visitors, and it should not carry the panel's assets,
    its Livewire runtime or anything that reads a session.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $title }}</title>
    <meta name="description" content="{{ $summary }}">

    {{-- The one URL this page is to be indexed under. The pages are reachable
         at one address only, but a crawler that arrived with a tracking query
         string should still be told which address that is. --}}
    <link rel="canonical" href="{{ $canonical }}">

    <meta property="og:type" content="{{ $ogType }}">
    <meta property="og:site_name" content="{{ config('app.name') }}">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $summary }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:locale" content="{{ str_replace('-', '_', app()->getLocale()) }}">
    @if ($image)
        <meta property="og:image" content="{{ $image }}">
        <meta property="og:image:alt" content="{{ $title }}">
    @endif

    {{-- The large card only when there is an image to fill it: asking for one
         and giving nothing renders as a broken panel, where `summary` renders
         as a tidy text card. --}}
    <meta name="twitter:card" content="{{ $image ? 'summary_large_image' : 'summary' }}">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $summary }}">
    @if ($image)
        <meta name="twitter:image" content="{{ $image }}">
    @endif

    {{-- What a search engine reads instead of guessing from the prose. The
         array is built in the controller's view data so the shapes stay in
         PHP, where they can be tested. --}}
    @if ($schema)
        <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    @endif

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/images/apple-touch-icon.png">

    @vite('resources/css/app.css')
</head>
<body class="min-h-full bg-white text-zinc-800 antialiased dark:bg-zinc-950 dark:text-zinc-200">
    <div class="mx-auto flex min-h-full max-w-4xl flex-col px-6 py-10 sm:py-16">
        <header class="mb-10 flex items-center justify-between gap-4">
            <a href="{{ $repository->pageUrl() }}" class="flex items-center gap-3 text-sm font-medium text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100">
                <img src="/images/logo.png" alt="" class="h-7 w-auto" width="28" height="28">
                <span>{{ config('app.name') }}</span>
            </a>

            @if (! $repository->isDefault())
                <span class="rounded-full bg-zinc-100 px-3 py-1 text-xs font-medium text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">
                    {{ $repository->name }}
                </span>
            @endif
        </header>

        <main class="flex-1">
            {{ $slot }}
        </main>

        <footer class="mt-16 border-t border-zinc-200 pt-6 text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-500">
            Served by {{ config('app.name') }} — a private Composer registry.
        </footer>
    </div>
</body>
</html>
