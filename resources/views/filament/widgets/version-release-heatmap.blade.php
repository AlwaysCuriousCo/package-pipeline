{{--
    Styled with a scoped <style> block and Filament's own colour custom
    properties rather than utility classes: the panel uses Filament's
    pre-built stylesheet, which only carries the classes Filament itself
    compiles, so app-level Tailwind would never reach this view.
--}}
<x-filament-widgets::widget class="fi-wi-version-release-heatmap">
    <x-filament::section
        heading="Release activity"
        :description="$summary"
    >
        <style>
            .pp-heatmap {
                --pp-gap: 3px;
                --pp-months-height: 0.875rem;
                --pp-label-color: var(--gray-500);
            }

            .dark .pp-heatmap {
                --pp-label-color: var(--gray-400);
            }

            .pp-heatmap-scroll {
                display: flex;
                gap: 0.5rem;
                overflow-x: auto;
                padding-bottom: 0.25rem;
            }

            .pp-heatmap-weekdays {
                display: grid;
                /* Clear the month row so the labels line up with the cells. */
                padding-top: calc(var(--pp-months-height) + var(--pp-gap));
                grid-template-rows: repeat(7, 1fr);
                gap: var(--pp-gap);
                flex: none;
            }

            .pp-heatmap-weekdays > span {
                display: flex;
                align-items: center;
                font-size: 0.6875rem;
                line-height: 1;
                color: var(--pp-label-color);
            }

            .pp-heatmap-calendar {
                /* Grow into a full-width widget, but never squash the cells past
                   legibility — below that the scroll container takes over. */
                flex: 1 1 auto;
                min-width: min-content;
            }

            .pp-heatmap-months,
            .pp-heatmap-weeks {
                display: grid;
                grid-template-columns: repeat(var(--pp-weeks), minmax(0.625rem, 1fr));
                gap: var(--pp-gap);
            }

            .pp-heatmap-months {
                height: var(--pp-months-height);
                margin-bottom: var(--pp-gap);
            }

            .pp-heatmap-months > span {
                font-size: 0.6875rem;
                line-height: 1;
                white-space: nowrap;
                color: var(--pp-label-color);
            }

            .pp-heatmap-week {
                display: grid;
                grid-template-rows: repeat(7, 1fr);
                gap: var(--pp-gap);
            }

            .pp-heatmap-cell {
                aspect-ratio: 1;
                border-radius: 2px;
                background-color: var(--gray-100);
                outline: 1px solid rgb(0 0 0 / 0.04);
                outline-offset: -1px;
            }

            .pp-heatmap-cell[data-level='1'] { background-color: var(--primary-200); }
            .pp-heatmap-cell[data-level='2'] { background-color: var(--primary-400); }
            .pp-heatmap-cell[data-level='3'] { background-color: var(--primary-500); }
            .pp-heatmap-cell[data-level='4'] { background-color: var(--primary-700); }

            .dark .pp-heatmap-cell {
                background-color: var(--gray-800);
                outline-color: rgb(255 255 255 / 0.04);
            }

            .dark .pp-heatmap-cell[data-level='1'] { background-color: var(--primary-950); }
            .dark .pp-heatmap-cell[data-level='2'] { background-color: var(--primary-800); }
            .dark .pp-heatmap-cell[data-level='3'] { background-color: var(--primary-600); }
            .dark .pp-heatmap-cell[data-level='4'] { background-color: var(--primary-400); }

            .pp-heatmap-cell--blank {
                visibility: hidden;
            }

            .pp-heatmap-legend {
                display: flex;
                align-items: center;
                justify-content: flex-end;
                gap: var(--pp-gap);
                margin-top: 0.5rem;
                font-size: 0.6875rem;
                line-height: 1;
                color: var(--pp-label-color);
            }

            .pp-heatmap-legend > .pp-heatmap-cell {
                width: 0.625rem;
                height: 0.625rem;
                aspect-ratio: auto;
            }

            .pp-heatmap-legend > span:first-child {
                margin-right: 0.25rem;
            }

            .pp-heatmap-legend > span:last-child {
                margin-left: 0.25rem;
            }
        </style>

        <div class="pp-heatmap" role="img" aria-label="{{ $summary }}">
            <div class="pp-heatmap-scroll">
                <div class="pp-heatmap-weekdays" aria-hidden="true">
                    @foreach (range(0, 6) as $row)
                        <span>{{ $weekdays[$row] ?? '' }}</span>
                    @endforeach
                </div>

                <div class="pp-heatmap-calendar" style="--pp-weeks: {{ count($weeks) }}">
                    <div class="pp-heatmap-months" aria-hidden="true">
                        @foreach ($months as $month)
                            <span style="grid-column-start: {{ $month['column'] }}">{{ $month['label'] }}</span>
                        @endforeach
                    </div>

                    <div class="pp-heatmap-weeks">
                        @foreach ($weeks as $week)
                            <div class="pp-heatmap-week">
                                @foreach ($week as $day)
                                    @if ($day === null)
                                        <span class="pp-heatmap-cell pp-heatmap-cell--blank"></span>
                                    @else
                                        <span
                                            class="pp-heatmap-cell"
                                            data-level="{{ $day['level'] }}"
                                            title="{{ $day['label'] }}"
                                        ></span>
                                    @endif
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="pp-heatmap-legend" aria-hidden="true">
                <span>Less</span>
                @foreach ($levels as $level)
                    <span class="pp-heatmap-cell" data-level="{{ $level }}"></span>
                @endforeach
                <span>More</span>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
