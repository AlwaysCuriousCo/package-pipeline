<div @if ($autoRun) wire:init="autoRunTest" @endif x-data="{ showDetails: false }">
    <p style="font-size: 0.875rem; margin-bottom: 0.75rem">This checks the public registry address, access credentials, package details and download. It uses a temporary token (a password for this test only), then deletes it.</p>
    <div wire:stream="installation-status">
        @include('filament.pages.get-started.test-status', ['status' => $status])
    </div>
    <x-filament::button color="gray" x-on:click="showDetails = ! showDetails" x-bind:aria-expanded="showDetails.toString()" aria-expanded="false" aria-controls="installation-details">
        Show details
    </x-filament::button>
    <div id="installation-details" x-cloak x-show="showDetails" style="margin-top: 0.75rem">
        @include('filament.terminal', ['lines' => $lines, 'stream' => 'installation-test', 'idle' => 'Requests and responses appear here as the test runs.'])
    </div>
</div>
