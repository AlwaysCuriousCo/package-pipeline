{{--
    A package page in Presentation Mode: the package's deck, full screen, in
    place of the ordinary page. @see https://github.com/AlwaysCuriousCo/package-pipeline/issues/55

    The head is the ordinary page's, so a link to it unfurls and indexes the
    same either way. Three additions are for what a deck cannot do on its own:
    `slides.txt` and the <noscript> copy carry the whole deck to readers that
    run no JavaScript, and the referrer policy keeps a presenter's signed link
    from leaving with a request for an image hosted somewhere else.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="same-origin">

    <x-page-meta
        :title="$package->name.' — '.config('app.name')"
        :summary="$package->pageSummary()"
        :canonical="$package->pageUrl()"
        :image="$package->pageImageUrl()"
        :schema="$schema"
    />

    <link rel="alternate" type="text/plain" href="{{ $package->pageUrl() }}/slides.txt" title="{{ $package->name }} — slides as text">
    <link rel="icon" href="/favicon.ico" sizes="any">

    {{-- The deck's families only: the ordinary page's Instrument Sans is not used here. --}}
    @fonts(['sofia-sans-extra-condensed', 'noto-sans', 'inter', 'jetbrains-mono'])
    @vite('resources/css/app.css')
    @livewireStyles

    {{-- Without JavaScript the deck is every slide stacked on top of the
         others, so it gives way to the transcript below it. --}}
    <noscript><style>body { overflow: auto !important; height: auto !important; background: #fff !important; } .pp-deck { display: none !important; }</style></noscript>
</head>
<body class="m-0 h-dvh overflow-hidden bg-[#05363D]">
    @livewire('package-deck', ['presentation' => $presentation])

    <noscript>
        <main class="mx-auto max-w-3xl px-6 py-12 text-zinc-800">
            @foreach ($slides as $slide)
                <section class="markdown border-b border-zinc-200 py-8">{!! $slide->html !!}</section>
            @endforeach
        </main>
    </noscript>

    @livewireScripts
</body>
</html>
