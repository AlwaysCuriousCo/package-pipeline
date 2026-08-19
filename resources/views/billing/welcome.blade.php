<x-page-layout
    :repository="$repository"
    :title="'Welcome — '.config('app.name')"
    :summary="'Your purchase is complete.'"
    :canonical="route('billing.welcome')"
>
    @if ($subscription === null)
        {{-- The completion webhook is racing this redirect and has not landed
             yet; the page refreshes itself until it does. --}}
        <meta http-equiv="refresh" content="3">

        <header class="mb-10">
            <h1 class="text-3xl font-semibold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">Finalizing your purchase…</h1>
            <p class="mt-4 text-zinc-600 dark:text-zinc-400">
                The payment went through and your subscription is being set up. This page refreshes itself — it usually takes a few seconds.
            </p>
        </header>
    @else
        <header class="mb-10">
            <h1 class="text-3xl font-semibold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">
                You're in
            </h1>
            <p class="mt-4 text-lg text-zinc-600 dark:text-zinc-400">
                Your {{ $subscription->plan->name }} subscription is active.
            </p>
        </header>

        @if ($plainToken !== null)
            <section class="mb-10 rounded-xl border border-emerald-300 dark:border-emerald-700">
                <h2 class="border-b border-emerald-200 px-5 py-3 text-sm font-semibold text-zinc-900 dark:border-emerald-800 dark:text-white">
                    Your access token — shown once, copy it now
                </h2>
                <div class="space-y-3 px-5 py-4">
                    <pre class="overflow-x-auto rounded-lg bg-zinc-900 px-4 py-3 text-sm text-zinc-100"><code>{{ $plainToken }}</code></pre>
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">Tell Composer about it, then require your packages as usual:</p>
                    <pre class="overflow-x-auto rounded-lg bg-zinc-900 px-4 py-3 text-sm text-zinc-100"><code>composer config --global http-basic.{{ parse_url($registryUrl, PHP_URL_HOST) }} token {{ $plainToken }}
composer config repositories.{{ \Illuminate\Support\Str::slug(config('app.name')) }} composer {{ $registryUrl }}</code></pre>
                </div>
            </section>
        @endif

        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            Tokens, invoices and your card live in <a href="{{ route('billing.index') }}" class="underline underline-offset-2">your billing area</a>.
        </p>
    @endif
</x-page-layout>
