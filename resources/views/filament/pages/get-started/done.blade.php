<div style="font-size: 0.875rem">
    <p><strong>✓ Your registry is ready</strong></p>
    <p style="margin-top: 0.5rem">The metadata, authentication and download checks passed. Next, open a terminal in your PHP project and run the commands below to install {{ $package?->name ?? 'your package' }} with Composer. The test does not run Composer’s dependency solver or install into a project.</p>
    @if ($package !== null && ! $package->composerRepository->public)
    <p style="margin-top: 0.5rem">For a private registry, Generate token creates read-only credentials for your project; copy them while they are shown. Keep those credentials out of source control.</p>
    @endif
</div>
