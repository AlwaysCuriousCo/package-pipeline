{{--
    Styled with a scoped <style> block and Filament's colour custom properties
    for the same reason as the release heatmap: the panel ships Filament's
    pre-built stylesheet, so app-level Tailwind never reaches this view.
--}}
<x-filament-widgets::widget class="fi-wi-registry-totals">
    <x-filament::section heading="Registry totals">
        <x-slot name="afterHeader">
            <x-filament::icon icon="heroicon-o-squares-2x2" class="pp-quad-icon" />
        </x-slot>

        <style>
            .fi-wi-registry-totals {
                --pp-divider: var(--gray-200);
                --pp-label-color: var(--gray-500);
            }

            .dark .fi-wi-registry-totals {
                --pp-divider: var(--gray-800);
                --pp-label-color: var(--gray-400);
            }

            .pp-quad-icon {
                width: 1.25rem;
                height: 1.25rem;
                color: var(--pp-label-color);
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

            .pp-quad-updated {
                margin: 0.25rem 0 0;
                padding-top: 0.75rem;
                border-top: 1px solid var(--pp-divider);
                font-size: 0.75rem;
                line-height: 1rem;
                color: var(--pp-label-color);
            }
        </style>

        <div
            class="pp-quad"
            @if ($pollingInterval)
                wire:poll.{{ $pollingInterval }}
            @endif
        >
            <dl class="pp-quad-grid">
                @foreach ($stats as $stat)
                    <div class="pp-quad-cell">
                        <dt class="pp-quad-label">{{ $stat['label'] }}</dt>
                        <dd class="pp-quad-value" data-tone="{{ $stat['tone'] }}">{{ $stat['value'] }}</dd>
                    </div>
                @endforeach
            </dl>

            {{--
                Counts up from the last render. The timestamp lives in an
                attribute rather than Alpine state because each poll morphs the
                attribute in place, restarting the counter — Alpine state would
                survive the morph and keep counting from the first page load.
            --}}
            <p
                class="pp-quad-updated"
                data-rendered-at="{{ now()->getTimestampMs() }}"
                x-data="{ tick: 0 }"
                x-init="setInterval(() => tick++, 1000)"
                x-text="(() => {
                    tick;
                    const seconds = Math.max(0, Math.floor((Date.now() - Number($el.dataset.renderedAt)) / 1000));
                    if (seconds < 60) return `Updated ${seconds} ${seconds === 1 ? 'second' : 'seconds'} ago`;
                    const minutes = Math.floor(seconds / 60);
                    return `Updated ${minutes} ${minutes === 1 ? 'minute' : 'minutes'} ago`;
                })()"
            >Updated 0 seconds ago</p>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
