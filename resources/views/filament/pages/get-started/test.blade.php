{{-- The test's stages, replaced in place as each request is reported. --}}
<div wire:stream="installation-status">
    @include('filament.pages.get-started.test-status', ['status' => $status])
</div>
