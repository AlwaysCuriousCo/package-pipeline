@props(['title', 'summary', 'canonical', 'image' => null, 'ogType' => 'website', 'schema' => null])

{{--
    Everything a machine reads about a public page, shared by the page layout
    and the Presentation Mode deck so the two can never disagree about it.
    @see resources/views/components/page-layout.blade.php
--}}
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
