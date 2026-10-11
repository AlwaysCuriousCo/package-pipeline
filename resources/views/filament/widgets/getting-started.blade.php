{{--
    Styled with a scoped <style> block and Filament's colour custom properties,
    like the other dashboard widgets: the panel ships Filament's pre-built
    stylesheet, so app-level Tailwind never reaches this view.
--}}
@php($step = $this->step())

<x-filament-widgets::widget class="fi-wi-getting-started">
    <x-filament::section heading="Getting started" description="From an empty registry to a package installed with Composer.">
        <x-slot name="afterHeader">
            {{ $this->dismissAction }}
        </x-slot>

        <style>
            .pp-getting-started { display: flex; gap: 1rem; align-items: center; justify-content: space-between; flex-wrap: wrap; }
            .pp-getting-started-step { margin: 0; font-size: 0.875rem; font-weight: 600; }
            .pp-getting-started-of { margin: 0.125rem 0 0; font-size: 0.875rem; color: var(--gray-500); }
            .dark .pp-getting-started-of { color: var(--gray-400); }
        </style>

        <div class="pp-getting-started">
            <div>
                <p class="pp-getting-started-step">{{ $step['title'] }}</p>
                <p class="pp-getting-started-of">Step {{ $step['number'] }} of {{ $step['total'] }}: connect GitHub, choose packages, let them sync, and install one.</p>
            </div>
            {{ $this->continueAction }}
        </div>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
