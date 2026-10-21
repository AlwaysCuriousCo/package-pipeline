<div class="text-sm">
    <p class="flex items-center gap-2 font-semibold" role="status">
        @if ($status['passed'] === null)
            <x-filament::loading-indicator class="size-5 text-primary-600" aria-hidden="true" />
            Testing your registry…
        @elseif ($status['passed'] === false)
            The installation test needs attention.
        @else
            Installation test passed.
        @endif
    </p>
    <ul class="mt-3 grid gap-2" aria-label="Installation test stages">
        @foreach ($status['stages'] as $stage)
            <li class="flex items-center gap-2 data-[state=failed]:text-danger-600 data-[state=passed]:text-success-600" data-state="{{ $stage['state'] }}">
                <span aria-hidden="true">{{ match ($stage['state']) { 'passed' => '✓', 'failed' => '✗', 'running' => '…', default => '○' } }}</span>
                <span>{{ $stage['label'] }}</span>
                <span class="fi-sr-only">{{ $stage['state'] }}</span>
            </li>
        @endforeach
    </ul>
    @if ($status['passed'] === false)
        <p>Could not complete: {{ $status['failure'] ?? 'the installation test' }}.</p>
        <p class="mt-2">{{ $status['hint'] ?? 'Check the registry address and its access settings, then run again. Show details to see the requests.' }}</p>
    @endif
</div>
