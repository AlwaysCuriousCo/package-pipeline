{{-- One package's first sync: what it is doing now, and its progress. The section around it holds the package's name and its Remove. --}}
@php($package = $row['package'])
<div class="grid gap-3 text-sm">
    <div class="flex items-center gap-2">
        @if ($row['state'] === 'syncing' && ! $expired)
            <x-filament::loading-indicator class="size-5 shrink-0 text-primary-600" data-role="sync-spinner" aria-hidden="true" />
        @endif
        <p role="status" @class(['text-danger-600 dark:text-danger-400' => in_array($row['state'], ['not a package', 'failed'], true), 'text-success-600 dark:text-success-400' => $row['state'] === 'ready'])>
            @if ($row['state'] === 'ready')
                Ready ({{ $row['versions'] }} {{ str('version')->plural($row['versions']) }})
            @elseif ($row['state'] === 'not a package')
                Not a package: no composer.json with a name.
            @elseif ($row['state'] === 'failed')
                Failed: {{ $row['error'] }}
            @elseif ($row['running'] && $row['discovering'])
                Asking GitHub for {{ $package->name }}'s tags and branches…
            @elseif ($row['running'])
                Importing versions: {{ $row['imported'] }} of {{ $row['total'] }} · building an archive for each
            @else
                Waiting for the queue worker to start… (the queue worker is the background process that runs syncs)
            @endif
        </p>
        @if ($row['state'] === 'syncing' && ! $expired)
            <span class="ms-auto whitespace-nowrap text-gray-500 dark:text-gray-400"
                data-role="sync-elapsed" aria-label="Time waiting for sync"
                x-data="{
                    elapsed: 0,
                    timer: null,
                    tick() { this.elapsed = Math.max(0, Math.floor(Date.now() / 1000) - $wire.syncWaitStartedAt) },
                    init() { this.tick(); this.timer = setInterval(() => this.tick(), 1000) },
                    destroy() { clearInterval(this.timer) },
                }"
                x-text="elapsed + ' s'"
            >0 s</span>
        @endif
    </div>
    @if ($row['running'] && ! $row['discovering'])
        <progress class="h-2 w-full accent-primary-600" role="progressbar" aria-label="Versions imported for {{ $package->name }}"
            aria-valuemin="0" aria-valuemax="{{ $row['total'] }}" aria-valuenow="{{ $row['imported'] }}"
            max="{{ max(1, $row['total']) }}" value="{{ $row['imported'] }}"></progress>
    @endif
    @if ($row['failed'] > 0 && $row['versions'] > 0)
        <p class="text-danger-600 dark:text-danger-400">{{ $row['failed'] }} {{ str('version')->plural($row['failed']) }} couldn't be imported; the others are fine.</p>
    @elseif ($row['failed'] > 0 && $row['running'])
        <p class="text-danger-600 dark:text-danger-400">{{ $row['failed'] }} {{ str('version')->plural($row['failed']) }} couldn't be imported; the remaining versions are still syncing.</p>
    @endif
</div>
