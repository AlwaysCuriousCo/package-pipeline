{{-- Matches the other sections on the profile page: the schema, then a save
     button aligned to the end. See App\Filament\Livewire\EmailNotificationsForm. --}}
<form wire:submit="updateEmailNotifications" class="fi-sc-form">
    {{ $this->form }}

    <div class="fi-ac fi-align-end">
        <x-filament::button type="submit">
            Save
        </x-filament::button>
    </div>
</form>
