{{--
    A terminal-style transcript: an installation test streams into it, and a
    deployment being followed polls into it. Its colours are the terminal-*
    tokens in the panel theme.
--}}
<div>

    {{-- Follows the output as it arrives, the way a terminal does. --}}
    <pre
        class="m-0 max-h-112 min-h-48 overflow-auto rounded-lg bg-terminal p-4 font-mono text-[0.8125rem]/[1.6] break-all whitespace-pre-wrap text-terminal-text"
        @if ($stream ?? null) wire:stream="{{ $stream }}" @endif
        @if ($poll ?? null) wire:poll.3s="{{ $poll }}" @endif
        x-data
        x-init="new MutationObserver(() => $el.scrollTop = $el.scrollHeight).observe($el, { childList: true }); $el.scrollTop = $el.scrollHeight"
    >@forelse ($lines as $line)@include('filament.terminal-line', $line)@empty<span class="text-terminal-muted">{{ $idle ?? '' }}</span>@endforelse</pre>
</div>
