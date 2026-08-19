<x-page-layout
    :repository="$repository"
    :title="'Pricing — '.config('app.name')"
    :summary="'Plans and pricing for the packages this registry publishes.'"
    :canonical="$canonical"
>
    <header class="mb-10">
        <h1 class="text-3xl font-semibold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">
            Pricing
        </h1>
        <p class="mt-4 text-lg text-zinc-600 dark:text-zinc-400">
            A subscription grants access to the packages its plan includes, installable with Composer the moment checkout completes.
        </p>
    </header>

    @if ($plans->isEmpty())
        <p class="text-sm text-zinc-500 dark:text-zinc-400">Nothing is on sale right now.</p>
    @else
        <div class="grid gap-6 sm:grid-cols-2">
            @foreach ($plans as $plan)
                <a href="{{ route('pages.pricing.plan', $plan) }}" class="block rounded-xl border border-zinc-200 p-6 hover:border-zinc-400 dark:border-zinc-800 dark:hover:border-zinc-600">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $plan->name }}</h2>

                    @if (filled($plan->description))
                        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{{ $plan->description }}</p>
                    @endif

                    <p class="mt-4 text-sm font-medium text-zinc-900 dark:text-white">
                        {{ $plan->prices->map->display()->join(' · ') ?: 'Contact us' }}
                    </p>

                    @if ($plan->trial_days > 0)
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $plan->trial_days }}-day free trial</p>
                    @endif
                </a>
            @endforeach
        </div>
    @endif
</x-page-layout>
