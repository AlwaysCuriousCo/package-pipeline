<div style="font-size: 0.875rem">
    <p><strong>✓ Your registry is ready</strong></p>
    <p style="margin-top: 0.5rem">The metadata, authentication and download checks passed. Next, open a terminal in your PHP project and run the commands below to install {{ $package?->name ?? 'your package' }} with Composer. The test does not run Composer’s dependency solver or install into a project.</p>
    @if ($package !== null && ! $package->composerRepository->public)
    <p style="margin-top: 0.5rem">This registry is private, so your project needs a token: a password that lets it read packages. Generate token creates a read-only one; copy it while it is shown, and keep it out of source control.</p>
    @endif
</div>
