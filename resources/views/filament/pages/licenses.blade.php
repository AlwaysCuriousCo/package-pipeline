@php($overview = $this->overview())

<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-3">
        <x-filament::section>
            <x-slot name="heading">Versions</x-slot>
            <p class="text-3xl font-semibold tabular-nums">{{ number_format($overview['totals']['versions']) }}</p>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                across {{ number_format($overview['totals']['packages']) }}
                {{ Str::plural('package', $overview['totals']['packages']) }}
            </p>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Licenses in use</x-slot>
            <p class="text-3xl font-semibold tabular-nums">
                {{ number_format($overview['licenses']->whereNotNull('license')->count()) }}
            </p>
            <p class="text-sm text-gray-500 dark:text-gray-400">distinct declarations</p>
        </x-filament::section>

        {{-- The number worth acting on: a version declaring no licence grants
             a consuming project nothing, whatever the repository looks like. --}}
        <x-filament::section>
            <x-slot name="heading">Declaring none</x-slot>
            <p @class([
                'text-3xl font-semibold tabular-nums',
                'text-danger-600 dark:text-danger-400' => $overview['totals']['undeclared'] > 0,
            ])>
                {{ number_format($overview['totals']['undeclared']) }}
            </p>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                @if ($overview['totals']['undeclared'] > 0)
                    versions with no grant at all
                @else
                    every version declares a license
                @endif
            </p>
        </x-filament::section>
    </div>

    @if ($overview['licenses']->isNotEmpty())
        <x-filament::section
            heading="Breakdown"
            description="Every license the registry publishes under, widest reach first."
            collapsible
        >
            <div class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($overview['licenses'] as $usage)
                    <div class="flex items-center justify-between gap-4 py-2">
                        <x-filament::badge :color="$usage->license === null ? 'danger' : 'success'">
                            {{ $usage->license ?? 'Not declared' }}
                        </x-filament::badge>

                        <span class="text-sm text-gray-500 tabular-nums dark:text-gray-400">
                            {{ number_format($usage->packages) }} {{ Str::plural('package', $usage->packages) }},
                            {{ number_format($usage->versions) }} {{ Str::plural('version', $usage->versions) }}
                        </span>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
