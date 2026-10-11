{{-- What the wizard leaves you with once an installation has passed. --}}
<div style="font-size: 0.875rem">
    <p><strong>Your registry works.</strong> Installed through its public address, just as a project would.</p>
    <p style="margin-top: 0.5rem; color: var(--gray-500)">
        @if ($package !== null)
            <a href="{{ \App\Filament\Pages\GetStarted::packageUrl($package) }}" style="text-decoration: underline">{{ $package->name }}'s page</a> has the commands for your own projects.
        @endif
        <a href="{{ \App\Filament\Pages\Dashboard::getUrl() }}" style="text-decoration: underline">Back to the dashboard</a>.
    </p>
</div>
