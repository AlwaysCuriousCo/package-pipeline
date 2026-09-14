@props(['label', 'value', 'hint' => null, 'accent' => false])

{{-- One copyable line: what it is, the thing itself, and the button that
     puts it on the clipboard. The value ships in the markup, so copying
     never round-trips the secret. --}}
<div
    x-data="{
        copied: false,
        copy() {
            window.navigator.clipboard.writeText(@js($value))
            this.copied = true
            setTimeout(() => (this.copied = false), 2000)
        },
    }"
    @class([
        'flex items-start justify-between gap-4 rounded-xl border p-4',
        'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5' => ! $accent,
        'border-success-600/30 bg-success-50 dark:border-success-500/30 dark:bg-success-500/10' => $accent,
    ])
>
    <div class="min-w-0 space-y-1.5">
        <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ $label }}
        </div>

        <code class="block break-all font-mono text-sm leading-relaxed text-gray-950 dark:text-gray-100">{{ $value }}</code>

        @if ($hint)
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</p>
        @endif
    </div>

    <x-filament::button
        size="sm"
        color="gray"
        icon="heroicon-m-clipboard-document"
        x-on:click="copy()"
        class="shrink-0"
    >
        <span x-show="! copied">Copy</span>
        <span x-show="copied" x-cloak>Copied</span>
    </x-filament::button>
</div>
