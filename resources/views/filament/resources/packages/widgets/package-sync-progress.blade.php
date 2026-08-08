{{--
    Styled with a scoped <style> block and Filament's colour custom properties
    rather than utility classes, for the same reason as the release heatmap:
    app-level Tailwind never reaches the panel's pre-built stylesheet.

    The poll is on the widget itself so the panel notices a sync starting or
    finishing without anyone reloading the page.
--}}
<x-filament-widgets::widget class="fi-wi-package-sync-progress">
    <div wire:poll.5s>
        @if ($running)
            <x-filament::section>
                <style>
                    .pp-sync-progress-row {
                        display: flex;
                        align-items: baseline;
                        justify-content: space-between;
                        gap: 1rem;
                    }

                    .pp-sync-progress-title {
                        font-size: 0.875rem;
                        font-weight: 600;
                    }

                    .pp-sync-progress-detail {
                        font-size: 0.875rem;
                        color: var(--gray-500);
                    }

                    .dark .pp-sync-progress-detail {
                        color: var(--gray-400);
                    }

                    .pp-sync-progress-failed {
                        color: var(--danger-600);
                    }

                    .pp-sync-progress-track {
                        margin-top: 0.75rem;
                        height: 0.5rem;
                        border-radius: 9999px;
                        overflow: hidden;
                        background-color: var(--gray-100);
                    }

                    .dark .pp-sync-progress-track {
                        background-color: rgba(255, 255, 255, 0.1);
                    }

                    .pp-sync-progress-bar {
                        height: 100%;
                        border-radius: 9999px;
                        background-color: var(--primary-600);
                        transition: width 0.5s ease;
                    }
                </style>

                <div class="pp-sync-progress-row">
                    <span class="pp-sync-progress-title">Sync in progress</span>

                    <span class="pp-sync-progress-detail">
                        @if ($discovering)
                            Fetching the list of versions from the repository…
                        @else
                            {{ $imported }} of {{ $total }} {{ str('version')->plural($total) }} imported
                            @if ($failed > 0)
                                · <span class="pp-sync-progress-failed">{{ $failed }} failed</span>
                            @endif
                        @endif
                    </span>
                </div>

                <div class="pp-sync-progress-track">
                    <div class="pp-sync-progress-bar" style="width: {{ $progress }}%"></div>
                </div>
            </x-filament::section>
        @endif
    </div>
</x-filament-widgets::widget>
