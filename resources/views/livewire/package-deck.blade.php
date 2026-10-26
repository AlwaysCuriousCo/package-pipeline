{{--
    SlideWire's deck, and beside it — for the presenter's signed link only —
    the notes, what comes next and a clock. @see App\Livewire\PackageDeck
--}}
<div class="pp-deck flex h-dvh w-full overflow-hidden">
    <div class="min-w-0 flex-1">
        @include('slidewire::livewire.presentation-deck')
    </div>

    @if ($presenter)
        <aside class="ac-presenter flex w-80 shrink-0 flex-col gap-6 overflow-y-auto border-l border-white/10 bg-[#073036] p-6 text-[#CECEC6] lg:w-96" aria-label="Presenter view">
            <div class="flex items-baseline justify-between">
                <span class="text-xs font-semibold uppercase tracking-widest text-[#83E0EE]">Slide {{ $presenter['position'] }} of {{ $presenter['count'] }}</span>

                {{-- Ignored by the morph so a slide change does not reset it. --}}
                <span wire:ignore
                      x-data="{ start: Date.now(), now: Date.now() }"
                      x-init="setInterval(() => now = Date.now(), 1000)"
                      x-text="new Date(now - start).toISOString().substring(11, 19)"
                      class="font-mono text-sm tabular-nums">00:00:00</span>
            </div>

            <section>
                <h2 class="ac-display mb-2 text-lg font-semibold text-[#F2FCFD]">Notes</h2>
                @forelse ($presenter['notes'] as $note)
                    <p class="mb-3 text-sm leading-relaxed whitespace-pre-line">{{ $note }}</p>
                @empty
                    <p class="text-sm text-[#A6A698]">No notes for this slide.</p>
                @endforelse
            </section>

            <section>
                <h2 class="ac-display mb-2 text-lg font-semibold text-[#F2FCFD]">Next</h2>
                <p class="text-sm leading-relaxed text-[#A6A698]">{{ $presenter['next'] ?? 'This is the last slide.' }}</p>
            </section>
        </aside>
    @endif
</div>
