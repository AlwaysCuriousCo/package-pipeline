<x-page-layout
    :repository="$repository"
    :title="$plan->name.' — '.config('app.name')"
    :summary="$plan->description ?: 'A plan on '.config('app.name')"
    :canonical="$canonical"
    og-type="product"
>
    <header class="mb-10">
        <h1 class="text-3xl font-semibold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">
            {{ $plan->name }}
        </h1>

        @if (filled($plan->description))
            <p class="mt-4 text-lg text-zinc-600 dark:text-zinc-400">{{ $plan->description }}</p>
        @endif
    </header>

    <section class="mb-10 rounded-xl border border-zinc-200 dark:border-zinc-800">
        <h2 class="border-b border-zinc-200 px-5 py-3 text-sm font-semibold text-zinc-900 dark:border-zinc-800 dark:text-white">
            Buy
        </h2>
        <div class="divide-y divide-zinc-100 dark:divide-zinc-900">
            @forelse ($plan->prices as $price)
                <div class="flex items-center justify-between gap-4 px-5 py-4">
                    <div>
                        <p class="font-medium text-zinc-900 dark:text-white">{{ $price->display() }}</p>
                        @if ($plan->trial_days > 0 && $price->interval->recurring())
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">Starts with a {{ $plan->trial_days }}-day free trial</p>
                        @endif
                        @if ($plan->billing_model === \App\Enums\BillingModel::OneTimeWithUpdates)
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">Includes {{ $plan->updates_window_months }} months of updates; what you have then is yours forever.</p>
                        @endif
                    </div>

                    @auth
                        <form method="POST" action="{{ route('billing.checkout', $price) }}">
                            @csrf
                            <button type="submit" class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                                Subscribe
                            </button>
                        </form>
                    @else
                        <a href="{{ config('registry.billing.public_signup') ? route('billing.register') : route('filament.admin.auth.login') }}" class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                            Sign {{ config('registry.billing.public_signup') ? 'up' : 'in' }} to buy
                        </a>
                    @endauth
                </div>
            @empty
                <p class="px-5 py-4 text-sm text-zinc-500 dark:text-zinc-400">Not on sale right now.</p>
            @endforelse
        </div>
    </section>

    @if ($plan->packages->isNotEmpty() || $plan->repositories->isNotEmpty())
        <section>
            <h2 class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">What it grants</h2>

            <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 dark:divide-zinc-900 dark:border-zinc-800">
                @foreach ($plan->repositories as $granted)
                    <li class="px-5 py-4">
                        <span class="font-medium text-zinc-900 dark:text-white">{{ $granted->name }}</span>
                        <span class="ml-2 text-xs text-zinc-500 dark:text-zinc-400">every package in the repository, present and future</span>
                    </li>
                @endforeach
                @foreach ($plan->packages as $granted)
                    <li class="px-5 py-4">
                        @if ($granted->page_enabled)
                            <a href="{{ $granted->pageUrl() }}" class="font-medium text-zinc-900 underline-offset-2 hover:underline dark:text-white">{{ $granted->name }}</a>
                        @else
                            <span class="font-medium text-zinc-900 dark:text-white">{{ $granted->name }}</span>
                        @endif
                        @if (filled($granted->description))
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $granted->description }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-page-layout>
