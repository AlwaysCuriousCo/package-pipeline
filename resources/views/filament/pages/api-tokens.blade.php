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

    {{-- The moment someone is about to make the wrong kind of token. --}}
    <p class="text-sm text-gray-500 dark:text-gray-400">
        Setting up CI or a server?
        @if (\App\Filament\Resources\DeployTokens\DeployTokenResource::canViewAny())
            Use a <a href="{{ \App\Filament\Resources\DeployTokens\DeployTokenResource::getUrl() }}" class="font-medium text-primary-600 underline dark:text-primary-400">deploy token</a> instead, so it isn't tied to your account.
        @else
            Ask an admin for a deploy token instead, so it isn't tied to your account.
        @endif
    </p>

    {{ $this->table }}
</x-filament-panels::page>
