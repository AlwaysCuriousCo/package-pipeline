<x-page-layout
    :repository="$repository"
    :title="'Billing — '.config('app.name')"
    :summary="'Your subscriptions, invoices and access tokens.'"
    :canonical="route('billing.index')"
>
    <header class="mb-10">
        <h1 class="text-3xl font-semibold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">Billing</h1>
        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">Signed in as {{ $user->email }}</p>
    </header>

    @if (session('status'))
        <div class="mb-8 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-200">
            {{ session('status') }}
        </div>
    @endif

    @if (session('plainToken'))
        <div class="mb-8 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 dark:border-emerald-700 dark:bg-emerald-950">
            <p class="text-sm font-medium text-emerald-900 dark:text-emerald-100">Your new token — copy it now, it is shown once:</p>
            <pre class="mt-2 overflow-x-auto rounded bg-zinc-900 px-3 py-2 text-sm text-zinc-100"><code>{{ session('plainToken') }}</code></pre>
        </div>
    @endif

    @if ($user->email_verified_at === null)
        <div class="mb-8 rounded-lg border border-zinc-300 bg-zinc-50 px-4 py-3 text-sm text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
            Your email address is not verified yet — buying needs a verified address.
            <form method="POST" action="{{ route('billing.verify.resend') }}" class="mt-2">
                @csrf
                <button type="submit" class="underline underline-offset-2">Resend the verification email</button>
            </form>
        </div>
    @endif

    <section class="mb-10">
        <h2 class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">Subscriptions</h2>

        @if ($customer === null || $customer->subscriptions->isEmpty())
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                No subscriptions yet — <a href="{{ route('pages.pricing') }}" class="underline underline-offset-2">see what is available</a>.
            </p>
        @else
            <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 dark:divide-zinc-900 dark:border-zinc-800">
                @foreach ($customer->subscriptions as $subscription)
                    <li class="px-5 py-4">
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <span class="font-medium text-zinc-900 dark:text-white">{{ $subscription->plan->name }}</span>
                                <span class="ml-2 rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400">{{ $subscription->status->getLabel() }}</span>
                            </div>
                            @if ($subscription->price)
                                <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $subscription->price->display() }}</span>
                            @endif
                        </div>

                        @if ($subscription->current_period_end)
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $subscription->cancel_at ? 'Ends' : 'Renews' }} {{ $subscription->current_period_end->toFormattedDateString() }}
                            </p>
                        @endif

                        @if ($subscription->grantsAccess())
                            <form method="POST" action="{{ route('billing.tokens.store', $subscription) }}" class="mt-3 flex items-center gap-2">
                                @csrf
                                <input type="text" name="name" required placeholder="Token name (e.g. CI)"
                                    class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                                <button type="submit" class="rounded-lg border border-zinc-300 px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-900">
                                    New token
                                </button>
                                @if ($subscription->plan->token_limit !== null)
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $subscription->tokens()->count() }}/{{ $subscription->plan->token_limit }} used</span>
                                @endif
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($tokens->isNotEmpty())
        <section class="mb-10">
            <h2 class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">Access tokens</h2>
            <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 dark:divide-zinc-900 dark:border-zinc-800">
                @foreach ($tokens as $token)
                    <li class="flex items-center justify-between gap-4 px-5 py-3">
                        <div>
                            <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $token->name }}</span>
                            <span class="ml-2 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $token->token_prefix }}…</span>
                            @if ($token->last_used_at)
                                <span class="ml-2 text-xs text-zinc-500 dark:text-zinc-400">last used {{ $token->last_used_at->diffForHumans() }}</span>
                            @endif
                        </div>
                        <form method="POST" action="{{ route('billing.tokens.destroy', $token) }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-sm text-red-600 underline-offset-2 hover:underline dark:text-red-400">Revoke</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($customer !== null && $customer->merchant->supportsPortal())
        <section class="mb-10">
            <h2 class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">Payment</h2>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                <a href="{{ route('billing.portal') }}" class="underline underline-offset-2">Manage your card, cancel, or download invoices</a> — handled securely by the payment provider.
            </p>
        </section>
    @endif

    @if ($customer !== null && $customer->invoices->isNotEmpty())
        <section>
            <h2 class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">Invoices</h2>
            <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 dark:divide-zinc-900 dark:border-zinc-800">
                @foreach ($customer->invoices as $invoice)
                    <li class="flex items-center justify-between gap-4 px-5 py-3 text-sm">
                        <span class="text-zinc-900 dark:text-white">{{ $invoice->number ?? 'Invoice' }}</span>
                        <span class="text-zinc-500 dark:text-zinc-400">{{ $invoice->issued_at?->toFormattedDateString() }}</span>
                        <span class="text-zinc-900 dark:text-white">{{ strtoupper($invoice->currency) }} {{ number_format($invoice->total / 100, 2) }}</span>
                        <span class="text-zinc-500 dark:text-zinc-400">{{ $invoice->status }}</span>
                        @if ($invoice->hosted_url)
                            <a href="{{ $invoice->hosted_url }}" target="_blank" rel="noopener" class="underline underline-offset-2">View</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-page-layout>
