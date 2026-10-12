{{-- Keep the poll through readiness/render races until checkSync redirects. --}}
@php($package = $status['package'])
<div @if ($mountedStep === 3 && ! $expired) wire:poll.3s.visible="checkSync" @endif class="pp-onboarding-sync">
    <style>
        .pp-onboarding-sync { font-size: 0.875rem; }
        .pp-onboarding-sync-stage { display: flex; gap: 0.5rem; align-items: center; margin-top: 0.75rem; }
        .pp-onboarding-sync-spinner { width: 1.25rem; height: 1.25rem; flex-shrink: 0; color: var(--primary-600); }
        .pp-onboarding-sync-elapsed { margin-left: auto; white-space: nowrap; color: var(--gray-500); }
        .pp-onboarding-sync-track { margin-top: 0.75rem; height: 0.5rem; border-radius: 9999px; overflow: hidden; background-color: var(--gray-100); }
        .pp-onboarding-sync-bar { height: 100%; border-radius: 9999px; background-color: var(--primary-600); transition: width 0.5s ease; }
        .pp-onboarding-sync-failure { margin-top: 0.5rem; color: var(--danger-600); }
        .dark .pp-onboarding-sync-elapsed { color: var(--gray-400); }
        .dark .pp-onboarding-sync-track { background-color: rgba(255, 255, 255, 0.1); }
    </style>

    <p>This is the first sync. The registry copies each tagged release from GitHub and packs it into a download Composer can install. This step moves on by itself when the first version is ready.</p>
    @if ($package === null)
        <p>Import a package first; its first sync starts as soon as it is imported.</p>
    @else
        <div class="pp-onboarding-sync-stage">
            @if ($waiting && ! $expired && blank($package->sync_error) && ($status['running'] || ! $status['hasBatch']))
                <x-filament::loading-indicator class="pp-onboarding-sync-spinner" aria-hidden="true" />
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
                    class="pp-onboarding-sync-elapsed"
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
            <div class="pp-onboarding-sync-track" role="progressbar" aria-label="Versions imported"
                 aria-valuemin="0" aria-valuemax="{{ $status['total'] }}" aria-valuenow="{{ $status['imported'] }}">
                <div class="pp-onboarding-sync-bar" style="width: {{ $status['total'] > 0 ? min(100, round(100 * $status['imported'] / $status['total'])) : 0 }}%"></div>
            </div>
        @endif
        @if ($status['failed'] > 0)
            <p class="pp-onboarding-sync-failure">{{ $status['failed'] }} {{ str('version')->plural($status['failed']) }} couldn't be imported; the others are fine.</p>
        @endif
        @if (filled($package->sync_error))
            <p class="pp-onboarding-sync-failure"><strong>{{ $package->name }}</strong> did not sync: {{ $package->sync_error }}</p>
            <p style="margin-top: 0.5rem">
                Fix it, then sync again from <a href="{{ \App\Filament\Pages\GetStarted::packageUrl($package) }}" style="text-decoration: underline">its page</a>; this step moves on once versions arrive.
            </p>
        @endif
    @endif
    @if ($expired)
        <p style="margin-top: 0.75rem">Still waiting for the first sync. Check that the queue worker is running; packages may still be syncing.</p>
        <button type="button" wire:click="recheckSync">Check again</button>
        <a href="https://github.com/AlwaysCuriousCo/package-pipeline/blob/main/docs/deployment.md#the-worker-and-the-scheduler-need-a-supervisor" target="_blank" rel="noopener">Keeping the queue worker running</a>
    @endif
</div>
