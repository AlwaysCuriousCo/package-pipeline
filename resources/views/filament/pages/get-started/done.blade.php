<div class="text-sm">
    <p><strong>✓ Your registry is ready</strong></p>
    <p class="mt-2">The metadata, authentication and download checks passed. Next, open a terminal in your PHP project and run the commands below to install {{ $package?->name ?? 'your package' }} with Composer. The test does not run Composer’s dependency solver or install into a project.</p>
    <ul class="mt-3 space-y-1" aria-label="Imported packages">
        @foreach ($packages as $status)
            <li><a href="{{ \App\Filament\Pages\GetStarted::packageUrl($status['package']) }}" class="underline">{{ $status['package']->name }}</a> → {{ $status['state'] }}</li>
        @endforeach
    </ul>
    @if ($package !== null && ! $package->composerRepository->public)
    <p class="mt-2">This registry is private, so your project needs a token: a password that lets it read packages. Generate token creates a read-only one; copy it while it is shown, and keep it out of source control.</p>
    @elseif ($package !== null)
    <p class="mt-2">This registry is public: anyone who can reach it can install {{ $package->name }} without a token. To require one, open Composer repositories → {{ $package->composerRepository->name }} and turn Public off.</p>
    @endif
</div>
