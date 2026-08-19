<x-page-layout
    :repository="$repository"
    :title="'Create an account — '.config('app.name')"
    :summary="'Create an account to subscribe to packages on '.config('app.name')"
    :canonical="route('billing.register')"
>
    <header class="mb-10">
        <h1 class="text-3xl font-semibold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">
            Create an account
        </h1>
        <p class="mt-4 text-zinc-600 dark:text-zinc-400">
            An account holds your subscriptions, invoices and access tokens.
            Already have one? <a href="{{ route('filament.admin.auth.login') }}" class="underline underline-offset-2">Sign in</a>.
        </p>
    </header>

    <form method="POST" action="{{ route('billing.register.store') }}" class="max-w-md space-y-5">
        @csrf

        {{-- The honeypot: hidden from people, filled by bots. --}}
        <div class="hidden" aria-hidden="true">
            <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
        </div>

        <div>
            <label for="name" class="block text-sm font-medium text-zinc-900 dark:text-white">Name</label>
            <input id="name" name="name" type="text" required value="{{ old('name') }}"
                class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
            @error('name')<p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="email" class="block text-sm font-medium text-zinc-900 dark:text-white">Email</label>
            <input id="email" name="email" type="email" required value="{{ old('email') }}"
                class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
            @error('email')<p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password" class="block text-sm font-medium text-zinc-900 dark:text-white">Password</label>
            <input id="password" name="password" type="password" required autocomplete="new-password"
                class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">At least 12 characters.</p>
            @error('password')<p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>@enderror
        </div>

        <div>
            <label for="password_confirmation" class="block text-sm font-medium text-zinc-900 dark:text-white">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password"
                class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
        </div>

        @if (config('registry.billing.terms_url'))
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                By creating an account you agree to the <a href="{{ config('registry.billing.terms_url') }}" class="underline underline-offset-2">terms of service</a>.
            </p>
        @endif

        <button type="submit" class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
            Create account
        </button>
    </form>
</x-page-layout>
