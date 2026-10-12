{{-- Keep polling through readiness/render races until checkSync redirects. --}}
<div @if ($mountedStep === 3 && ! $expired) wire:poll.3s.visible="checkSync" @endif class="text-sm">
    <p>This is the first sync. The registry copies each tagged release from GitHub and packs it into a download Composer can install. This step moves on by itself when every package has finished syncing and at least one is ready.</p>
    @forelse ($status as $row)
        @php($package = $row['package'])
        <div wire:key="sync-package-{{ $package->id }}" class="mt-3">
            <div class="flex items-center gap-2">
                @if ($row['state'] === 'syncing' && ! $expired)
                    <x-filament::loading-indicator class="size-5 shrink-0 text-primary-600" data-role="sync-spinner" aria-hidden="true" />
                @endif
                <p role="status">
                    <a href="{{ \App\Filament\Pages\GetStarted::packageUrl($package) }}" class="font-semibold underline">{{ $package->name }}</a> →
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
                @if (in_array($row['state'], ['not a package', 'failed'], true) && in_array($package->id, session(\App\Filament\Pages\GetStarted::PACKAGES_KEY, []), true) && auth()->user()?->can('delete', $package))
                    <x-filament::button color="danger" size="sm" wire:click="removePackage({{ $package->id }})" wire:confirm="Remove {{ $package->name }} from this registry?" aria-label="Remove {{ $package->name }}">Remove</x-filament::button>
                @endif
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
                <progress class="mt-3 h-2 w-full accent-primary-600" role="progressbar" aria-label="Versions imported for {{ $package->name }}"
                    aria-valuemin="0" aria-valuemax="{{ $row['total'] }}" aria-valuenow="{{ $row['imported'] }}"
                    max="{{ max(1, $row['total']) }}" value="{{ $row['imported'] }}"></progress>
            @endif
            @if ($row['failed'] > 0 && $row['versions'] > 0)
                <p class="mt-2 text-danger-600">{{ $row['failed'] }} {{ str('version')->plural($row['failed']) }} couldn't be imported; the others are fine.</p>
            @elseif ($row['failed'] > 0 && $row['running'])
                <p class="mt-2 text-danger-600">{{ $row['failed'] }} {{ str('version')->plural($row['failed']) }} couldn't be imported; the remaining versions are still syncing.</p>
            @endif
        </div>
    @empty
        <p>Import a package first; its first sync starts as soon as it is imported.</p>
    @endforelse
    @if ($allSettled && ! in_array('ready', array_column($status, 'state'), true) && $status !== [])
        <p class="mt-3">Every sync has finished, but no package is ready to install. Open a package's page to fix it and sync again, or remove it and choose another repository.</p>
        <x-filament::button color="gray" wire:click="recheckSync">Check again</x-filament::button>
    @elseif ($expired)
        <p class="mt-3">Still waiting for the first sync. Check that the queue worker is running; packages may still be syncing.</p>
        <x-filament::button color="gray" wire:click="recheckSync">Check again</x-filament::button>
        <a href="https://github.com/AlwaysCuriousCo/package-pipeline/blob/main/docs/deployment.md#the-worker-and-the-scheduler-need-a-supervisor" target="_blank" rel="noopener" class="underline">Keeping the queue worker running</a>
    @endif
</div>
