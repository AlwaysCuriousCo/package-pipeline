<?php

use Illuminate\Support\Facades\Route;

// The app is administered entirely through Filament, so the root URL lands on
// the panel's login page. Keep this in sync with the admin panel's path()
// in App\Providers\Filament\AdminPanelProvider.
Route::redirect('/', '/admin/login')->name('home');
