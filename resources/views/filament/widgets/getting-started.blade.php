@php($step = $this->step())

<x-filament-widgets::widget class="fi-wi-getting-started">
    <x-filament::section heading="Getting started" description="From an empty registry to a package installed with Composer.">
        <x-slot name="afterHeader">
            {{ $this->dismissAction }}
        </x-slot>

        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="m-0 text-sm font-semibold">{{ $step['title'] }}</p>
                <p class="mt-0.5 mb-0 text-sm text-gray-500 dark:text-gray-400">Step {{ $step['number'] }} of {{ $step['total'] }}: connect GitHub, choose packages, let them sync, and install one.</p>
            </div>
            {{ $this->continueAction }}
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
