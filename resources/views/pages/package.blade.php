<x-page-layout
    :repository="$repository"
    :title="$package->name.' — '.config('app.name')"
    :summary="$package->pageSummary()"
    :canonical="$package->pageUrl()"
    :image="$package->pageImageUrl()"
    og-type="website"
    :schema="$schema"
>
    <article>
        <header class="mb-10">
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-3xl font-semibold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">
                    {{ $package->name }}
                </h1>

                @if ($package->latest_version)
                    <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                        {{ $package->latest_version }}
                    </span>
                @endif

                @if ($package->abandoned)
                    {{-- The one badge that is a warning rather than a fact.
                         Composer tells a consumer this at install time; a page
                         that did not would be quietly recruiting new users to
                         something nobody is maintaining. --}}
                    <span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                        Abandoned{{ $package->replacement_package ? ' — use '.$package->replacement_package : '' }}
                    </span>
                @endif
            </div>

            @if (filled($package->description))
                <p class="mt-4 text-lg text-zinc-600 dark:text-zinc-400">{{ $package->description }}</p>
            @endif

            @if (($package->page_type && $package->type) || ($package->page_source && filled($package->repository)))
                <dl class="mt-6 flex flex-wrap gap-x-8 gap-y-3 text-sm text-zinc-500 dark:text-zinc-400">
                    @if ($package->page_type && $package->type)
                        <div><dt class="inline font-medium text-zinc-700 dark:text-zinc-300">Type:</dt> <dd class="inline">{{ $package->type }}</dd></div>
                    @endif

                    {{-- Off unless an admin switched it on: this is the one
                         line on a page that names infrastructure rather than
                         describing a package. --}}
                    @if ($package->page_source && filled($package->repository))
                        <div>
                            <dt class="inline font-medium text-zinc-700 dark:text-zinc-300">Source:</dt>
                            <dd class="inline"><a class="underline underline-offset-2 hover:text-zinc-900 dark:hover:text-zinc-100" href="{{ $package->repository }}" rel="noopener">{{ $package->repository }}</a></dd>
                        </div>
                    @endif
                </dl>
            @endif
        </header>

        @if ($package->pageRequiresAccess())
            {{-- What stands in for the install commands and the download
                 buttons on a package whose repository is private. The page
                 still describes the package — that is the whole point of
                 publishing one — but everything on it that would need a
                 credential says so plainly instead of sending a visitor to a
                 401 that reads as the registry being broken.

                 This block is where a "request access" form will go. --}}
            <section class="mb-10 rounded-xl border border-amber-200 bg-amber-50/60 p-5 dark:border-amber-900 dark:bg-amber-950/40">
                <h2 class="text-sm font-semibold text-amber-900 dark:text-amber-200">Access required</h2>
                <p class="mt-1 text-sm text-amber-800 dark:text-amber-300">
                    This package is served from a private repository. Installing it needs an access token
                    for {{ config('app.name') }}; ask whoever administers this registry for one.
                </p>
            </section>
        @endif

        @if ($commands)
            <section class="mb-10 rounded-xl border border-zinc-200 dark:border-zinc-800">
                <h2 class="border-b border-zinc-200 px-5 py-3 text-sm font-semibold text-zinc-900 dark:border-zinc-800 dark:text-white">Install</h2>

                <div class="space-y-4 px-5 py-4">
                    <div>
                        <p class="mb-1 text-xs text-zinc-500 dark:text-zinc-400">1. Register this Composer repository (once per project)</p>
                        <pre class="overflow-x-auto rounded-lg bg-zinc-900 px-4 py-3 text-sm text-zinc-100"><code>{{ $commands['repository'] }}</code></pre>
                    </div>
                    <div>
                        <p class="mb-1 text-xs text-zinc-500 dark:text-zinc-400">2. Require the package</p>
                        <pre class="overflow-x-auto rounded-lg bg-zinc-900 px-4 py-3 text-sm text-zinc-100"><code>{{ $commands['require'] }}</code></pre>
                    </div>
                </div>
            </section>
        @endif

        @if ($latest)
            <section class="mb-10">
                <a
                    href="{{ $repository->url('/p/'.$package->name.'/download') }}"
                    class="inline-flex items-center gap-2 rounded-lg bg-zinc-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200"
                    {{-- A download is not a document: nothing should follow
                         this link to index what is behind it. --}}
                    rel="nofollow"
                >
                    Download {{ $latest->version }}
                </a>
            </section>
        @endif

        @if ($body)
            <section class="markdown mb-12">{!! $body !!}</section>
        @endif

        @if ($versions->isNotEmpty())
            <section>
                <h2 class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">Versions</h2>

                <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-800">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs text-zinc-500 dark:text-zinc-400">
                            <tr class="border-b border-zinc-200 dark:border-zinc-800">
                                <th class="px-4 py-2 font-medium">Version</th>
                                <th class="px-4 py-2 font-medium">Released</th>
                                @if ($downloads === \App\Enums\PageDownloads::All)
                                    <th class="px-4 py-2 font-medium"><span class="sr-only">Download</span></th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($versions as $version)
                                <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-900">
                                    <td class="px-4 py-2 font-mono text-xs text-zinc-900 dark:text-zinc-100">{{ $version->version }}</td>
                                    <td class="px-4 py-2 text-zinc-500 dark:text-zinc-400">
                                        {{ $version->released_at?->toFormattedDateString() ?? '—' }}
                                    </td>
                                    @if ($downloads === \App\Enums\PageDownloads::All)
                                        <td class="px-4 py-2 text-right">
                                            @if ($version->archive_path)
                                                <a
                                                    href="{{ $repository->url('/p/'.$package->name.'/download/'.rawurlencode($version->version)) }}"
                                                    class="text-xs underline underline-offset-2 hover:text-zinc-900 dark:hover:text-zinc-100"
                                                    rel="nofollow"
                                                >zip</a>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </article>
</x-page-layout>
