{{--
    Styled with a scoped <style> block and Filament's colour custom properties
    for the same reason as the release heatmap: the panel ships Filament's
    pre-built stylesheet, so app-level Tailwind never reaches this view.
--}}
<x-filament-widgets::widget class="fi-wi-registry-totals">
    <x-filament::section heading="Registry totals">
        <style>
            .fi-wi-registry-totals {
                --pp-divider: var(--gray-200);
                --pp-label-color: var(--gray-500);
            }

            .dark .fi-wi-registry-totals {
                --pp-divider: var(--gray-800);
                --pp-label-color: var(--gray-400);
            }

            .pp-quad-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                margin: 0;
            }

            .pp-quad-cell {
                display: grid;
                gap: 0.375rem;
                justify-items: center;
                padding: 1.25rem 1rem;
                text-align: center;
            }

            .pp-quad-cell:nth-child(odd) {
                border-inline-end: 1px solid var(--pp-divider);
            }

            .pp-quad-cell:nth-child(-n + 2) {
                border-block-end: 1px solid var(--pp-divider);
            }

            .pp-quad-label {
                font-size: 0.875rem;
                line-height: 1.25rem;
                color: var(--pp-label-color);
            }

            .pp-quad-value {
                margin: 0;
                font-size: 1.875rem;
                line-height: 2.25rem;
                font-weight: 600;
            }

            .pp-quad-value[data-tone='info'] { color: var(--info-600); }
            .pp-quad-value[data-tone='success'] { color: var(--success-600); }

            /* The 600 steps vanish against a dark surface; step up to the
               shades that keep large-text contrast there. */
            .dark .pp-quad-value[data-tone='info'] { color: var(--info-400); }
            .dark .pp-quad-value[data-tone='success'] { color: var(--success-400); }

            /* The downloads chart shares this dashboard row. Stretch both
               cards to the row's height and centre their content, so the
               pair reads as one band whichever is naturally taller. */
            .fi-wi-registry-totals,
            .fi-wi-chart,
            .fi-wi-registry-totals > .fi-section,
            .fi-wi-chart > .fi-section {
                height: 100%;
            }

            .fi-wi-registry-totals > .fi-section,
            .fi-wi-chart > .fi-section {
                display: flex;
                flex-direction: column;
            }

            .fi-wi-registry-totals .fi-section-content-ctn,
            .fi-wi-chart .fi-section-content-ctn {
                display: flex;
                flex: 1 1 auto;
                flex-direction: column;
                justify-content: center;
            }
        </style>

        <dl
            class="pp-quad-grid"
            @if ($pollingInterval)
                wire:poll.{{ $pollingInterval }}
            @endif
        >
            @foreach ($stats as $stat)
                <div class="pp-quad-cell">
                    <dt class="pp-quad-label">{{ $stat['label'] }}</dt>
                    <dd class="pp-quad-value" data-tone="{{ $stat['tone'] }}">{{ $stat['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    </x-filament::section>
</x-filament-widgets::widget>
