@php
    $ssoSources = \App\Models\AuthenticationSource::query()
        ->where('active', true)
        ->orderBy('name')
        ->get();
@endphp

@if (session('sso_error'))
    <p class="text-center text-sm text-danger-600 dark:text-danger-400">
        {{ session('sso_error') }}
    </p>
@endif

@if ($ssoSources->isNotEmpty())
    <div class="grid gap-2">
        @foreach ($ssoSources as $ssoSource)
            <x-filament::button
                tag="a"
                color="gray"
                outlined
                href="{{ route('sso.redirect', $ssoSource) }}"
            >
                Continue with {{ $ssoSource->name }}
            </x-filament::button>
        @endforeach
    </div>
@endif
