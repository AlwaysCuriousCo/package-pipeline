<x-filament-panels::page>
    @if ($plainTextToken)
        <x-filament::section
            icon="heroicon-o-key"
            icon-color="success"
            heading="Copy your new token now"
            description="This is the only time it is shown. Configure Composer on the consuming machine with:"
        >
            <pre class="overflow-x-auto rounded-lg bg-gray-950 p-4 text-sm text-gray-100"><code>composer config http-basic.{{ request()->getHost() }} token {{ $plainTextToken }}</code></pre>
        </x-filament::section>
    @endif

    {{ $this->table }}
</x-filament-panels::page>
