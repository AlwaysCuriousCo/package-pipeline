<?php

return [
    /*
    | Filament's widgets are this app's only Livewire components, so point
    | Livewire — and Wirebones' #[Wirebone] discovery, which scans this
    | path — at app/Filament instead of the unused app/Livewire.
    */
    'class_namespace' => 'App\\Filament',

    'class_path' => app_path('Filament'),
];
