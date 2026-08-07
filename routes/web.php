<?php

use App\Http\Controllers\ComposerRepositoryController;
use Illuminate\Support\Facades\Route;

// The app is administered entirely through Filament, so the root URL lands on
// the panel's login page. Keep this in sync with the admin panel's path()
// in App\Providers\Filament\AdminPanelProvider.
Route::redirect('/', '/admin/login')->name('home');

// The Composer v2 repository API. A consuming project opts in with:
//   composer config repositories.private composer <this-app's-url>
Route::get('/packages.json', [ComposerRepositoryController::class, 'root'])
    ->name('composer.root');
Route::get('/p2/{vendor}/{package}.json', [ComposerRepositoryController::class, 'metadata'])
    // Greedy segment so package names containing dots still match.
    ->where('package', '[^/]+')
    ->name('composer.metadata');
Route::get('/dist/{vendor}/{package}/{reference}.zip', [ComposerRepositoryController::class, 'dist'])
    ->name('composer.dist');
