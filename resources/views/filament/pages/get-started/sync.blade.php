{{-- Keep the poll through readiness/render races until checkSync redirects. --}}
@php($package = $status['package'])
<div @if ($mountedStep === 3 && ! $expired) wire:poll.3s.visible="checkSync" @endif class="text-sm">
    <p>This is the first sync. The registry copies each tagged release from GitHub and packs it into a download Composer can install. This step moves on by itself when the first version is ready.</p>
    @if ($package === null)
        <p>Import a package first; its first sync starts as soon as it is imported.</p>
    @else
        <div class="mt-3 flex items-center gap-2">
            @if ($waiting && ! $expired && blank($package->sync_error) && ($status['running'] || ! $status['hasBatch']))
                <x-filament::loading-indicator class="size-5 shrink-0 text-primary-600" data-role="sync-spinner" aria-hidden="true" />
            @endif
            <p role="status">
                @if ($status['versions'] > 0)
                    Ready: {{ $status['versions'] }} {{ str('version')->plural($status['versions']) }} to install. This moves on by itself.
                @elseif ($status['running'] && $status['discovering'])
                    Asking GitHub for {{ $package->name }}'s tags and branches…
                @elseif ($status['running'])
                    Importing versions: {{ $status['imported'] }} of {{ $status['total'] }} · building an archive for each
                @elseif (! $status['hasBatch'] && blank($package->sync_error))
                    Waiting for the queue worker to start… (the queue worker is the background process that runs syncs)
                @else
                    No versions are ready to install yet.
                @endif
            </p>
            @if ($waiting && ! $expired)
                <span
                    class="ms-auto whitespace-nowrap text-gray-500 dark:text-gray-400"
                    data-role="sync-elapsed"
                    aria-label="Time waiting for sync"
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
        @if ($status['running'] && ! $status['discovering'] && $status['versions'] === 0)
            <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10" role="progressbar" aria-label="Versions imported"
                 aria-valuemin="0" aria-valuemax="{{ $status['total'] }}" aria-valuenow="{{ $status['imported'] }}">
                {{-- The width is computed per render, so only the variable is inline. --}}
                <div class="h-full w-(--w) rounded-full bg-primary-600 transition-[width] duration-500 ease-out" style="--w: {{ $status['total'] > 0 ? min(100, round(100 * $status['imported'] / $status['total'])) : 0 }}%"></div>
            </div>
        @endif
        @if ($status['failed'] > 0)
            <p class="mt-2 text-danger-600">{{ $status['failed'] }} {{ str('version')->plural($status['failed']) }} couldn't be imported; the others are fine.</p>
        @endif
        @if (filled($package->sync_error))
            <p class="mt-2 text-danger-600"><strong>{{ $package->name }}</strong> did not sync: {{ $package->sync_error }}</p>
            <p class="mt-2">
                Fix it, then sync again from <a href="{{ \App\Filament\Pages\GetStarted::packageUrl($package) }}" class="underline">its page</a>; this step moves on once versions arrive.
            </p>
        @endif
    @endif
    @if ($expired)
        <p class="mt-3">Still waiting for the first sync. Check that the queue worker is running; packages may still be syncing.</p>
        <button type="button" wire:click="recheckSync">Check again</button>
        <a href="https://github.com/AlwaysCuriousCo/package-pipeline/blob/main/docs/deployment.md#the-worker-and-the-scheduler-need-a-supervisor" target="_blank" rel="noopener">Keeping the queue worker running</a>
    @endif
</div>
