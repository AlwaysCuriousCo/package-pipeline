{{--
    The wizard's wait for a first sync. The poll remains stable while the visible step waits, and
    moves the page on once the first versions land: nobody has to know a
    reload is needed.
--}}
<div @unless ($expired) wire:poll.3s.visible="checkSync" @endunless style="font-size: 0.875rem">
    @if ($expired)
        <p>Still waiting for the first sync. Check that the queue worker is running; packages may still be syncing.</p>
        <button type="button" wire:click="recheckSync">Check again</button>
        <a href="https://github.com/AlwaysCuriousCo/package-pipeline/blob/main/docs/deployment.md#the-worker-and-the-scheduler-need-a-supervisor" target="_blank" rel="noopener">Keeping the queue worker running</a>
    @endif
    @if ($package === null)
        <p>Import a package first; its first sync starts as soon as it is imported.</p>
    @elseif (filled($package->sync_error))
        <p><strong>{{ $package->name }}</strong> did not sync: {{ $package->sync_error }}</p>
        <p style="margin-top: 0.5rem; color: var(--gray-500)">
            Fix it, then sync again from <a href="{{ \App\Filament\Pages\GetStarted::packageUrl($package) }}" style="text-decoration: underline">its page</a>; this step moves on once versions arrive.
        </p>
    @elseif ($waiting)
        <p>Syncing <strong>{{ $package->name }}</strong>: the queue worker is reading its tags and branches and building an archive for each. This moves on by itself.</p>
    @else
        <p><strong>{{ $package->name }}</strong> has versions to install.</p>
    @endif
</div>
