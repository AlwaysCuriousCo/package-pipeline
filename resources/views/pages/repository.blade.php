<x-page-layout
    :repository="$repository"
    :title="$repository->name.' — '.config('app.name')"
    :summary="$repository->pageSummary()"
    :canonical="$repository->pageUrl()"
    :image="$repository->pageImageUrl()"
    :schema="$schema"
>
    <header class="mb-10">
        <h1 class="text-3xl font-semibold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">
            {{ $repository->name }}
        </h1>

        @if (filled($repository->description))
            <p class="mt-4 text-lg text-zinc-600 dark:text-zinc-400">{{ $repository->description }}</p>
        @endif

        <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">
            @if ($repository->public)
                A public Composer repository — readable without a token.
            @else
                A private Composer repository. Installing from it needs an access token.
            @endif
        </p>
    </header>

    <section class="mb-10 rounded-xl border border-zinc-200 dark:border-zinc-800">
        <h2 class="border-b border-zinc-200 px-5 py-3 text-sm font-semibold text-zinc-900 dark:border-zinc-800 dark:text-white">
            Point a project at this repository
        </h2>
        <div class="px-5 py-4">
            <pre class="overflow-x-auto rounded-lg bg-zinc-900 px-4 py-3 text-sm text-zinc-100"><code>{{ $repository->configureCommand() }}</code></pre>
        </div>
    </section>

    @if ($body)
        <section class="markdown mb-12">{!! $body !!}</section>
    @endif

    @if ($packages->isNotEmpty())
        <section>
            <h2 class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">Packages</h2>

            <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 dark:divide-zinc-900 dark:border-zinc-800">
                @foreach ($packages as $listed)
                    <li class="px-5 py-4">
                        <a href="{{ $listed->pageUrl() }}" class="font-medium text-zinc-900 underline-offset-2 hover:underline dark:text-white">
                            {{ $listed->name }}
                        </a>

                        @if ($listed->latest_version)
                            <span class="ml-2 text-xs text-zinc-500 dark:text-zinc-400">{{ $listed->latest_version }}</span>
                        @endif

                        @if ($listed->abandoned)
                            <span class="ml-2 text-xs text-amber-700 dark:text-amber-400">abandoned</span>
                        @endif

                        @if (filled($listed->description))
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $listed->description }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-page-layout>
