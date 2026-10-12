<div class="pp-onboarding-test-status">
    <style>
        .pp-onboarding-test-status { font-size: 0.875rem; }
        .pp-onboarding-test-title { display: flex; align-items: center; gap: 0.5rem; font-weight: 600; }
        .pp-onboarding-test-spinner { width: 1.25rem; height: 1.25rem; color: var(--primary-600); }
        .pp-onboarding-test-stages { margin: 0.75rem 0; display: grid; gap: 0.5rem; }
        .pp-onboarding-test-stages li { display: flex; align-items: center; gap: 0.5rem; }
        .pp-onboarding-test-stages [data-state="passed"] { color: var(--success-600); }
        .pp-onboarding-test-stages [data-state="failed"] { color: var(--danger-600); }
    </style>
    <p class="pp-onboarding-test-title" role="status">
        @if ($status['passed'] === null)
            <x-filament::loading-indicator class="pp-onboarding-test-spinner" aria-hidden="true" />
            Testing your registry…
        @elseif ($status['passed'] === false)
            The installation test needs attention.
        @else
            Installation test passed.
        @endif
    </p>
    <ul class="pp-onboarding-test-stages" aria-label="Installation test stages">
        @foreach ($status['stages'] as $stage)
            <li data-state="{{ $stage['state'] }}">
                <span aria-hidden="true">{{ match ($stage['state']) { 'passed' => '✓', 'failed' => '✗', 'running' => '…', default => '○' } }}</span>
                <span>{{ $stage['label'] }}</span>
                <span class="fi-sr-only">{{ $stage['state'] }}</span>
            </li>
        @endforeach
    </ul>
    @if ($status['passed'] === false)
        <p>Could not complete: {{ $status['failure'] ?? 'the installation test' }}.</p>
        <p style="margin-top: 0.5rem">{{ $status['hint'] ?? 'Check the registry address and its access settings, then run again. Show details to see the requests.' }}</p>
    @endif
</div>
