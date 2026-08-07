<?php

namespace App\Providers;

use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Laravel's policy discovery finds App\Policies\PackagePolicy and
        // friends on its own, but not the policy for Shield's own Role model,
        // which lives in a vendor namespace. This registers it.
        FilamentShield::enforcePolicies();
    }
}
