{{--
    Styled with a scoped <style> block and Filament's colour custom properties,
    like the other dashboard widgets: the panel ships Filament's pre-built
    stylesheet, so app-level Tailwind never reaches this view.
--}}
<x-filament-widgets::widget class="fi-wi-getting-started">
    <x-filament::section heading="Getting started" description="From an empty registry to a package installed with Composer.">
        <x-slot name="afterHeader">
            {{ $this->dismissAction }}
        </x-slot>

        <style>
            .pp-steps { display: grid; gap: 0; margin: 0; padding: 0; list-style: none; }
            .pp-step { display: flex; gap: 0.875rem; align-items: center; padding: 0.875rem 0; }
            .pp-step + .pp-step { border-block-start: 1px solid var(--gray-200); }
            .dark .pp-step + .pp-step { border-block-start-color: var(--gray-800); }
            .pp-step-marker {
                display: grid; place-items: center; flex: none;
                width: 1.75rem; height: 1.75rem; border-radius: 9999px;
                font-size: 0.875rem; font-weight: 600;
                color: var(--gray-500); border: 1px solid var(--gray-300);
            }
            .dark .pp-step-marker { border-color: var(--gray-700); color: var(--gray-400); }
            .pp-step-marker[data-done] { border: none; color: var(--success-600); }
            .dark .pp-step-marker[data-done] { color: var(--success-400); }
            .pp-step-body { flex: 1 1 auto; min-width: 0; }
            .pp-step-title { margin: 0; font-size: 0.875rem; font-weight: 600; }
            .pp-step-title[data-done] { color: var(--gray-500); text-decoration: line-through; }
            .pp-step-description { margin: 0.125rem 0 0; font-size: 0.875rem; color: var(--gray-500); }
            .dark .pp-step-description { color: var(--gray-400); }
            .pp-step-action { flex: none; }
        </style>

        <ol class="pp-steps">
            @foreach ($this->steps() as $index => $step)
                <li class="pp-step">
                    <span class="pp-step-marker" @if ($step['done']) data-done @endif>
                        @if ($step['done'])
                            <x-filament::icon :icon="\Filament\Support\Icons\Heroicon::CheckCircle" class="h-7 w-7" style="width: 1.75rem; height: 1.75rem" />
                        @else
                            {{ $index + 1 }}
                        @endif
                    </span>
                    <div class="pp-step-body">
                        <p class="pp-step-title" @if ($step['done']) data-done @endif>{{ $step['title'] }}</p>
                        <p class="pp-step-description">{{ $step['description'] }}</p>
                    </div>
                    @if (! $step['done'] && $step['available'])
                        <div class="pp-step-action">
                            {{ $this->getAction($step['key']) }}
                        </div>
                    @endif
                </li>
            @endforeach
        </ol>
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-widgets::widget>
