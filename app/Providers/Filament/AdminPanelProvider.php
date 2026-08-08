<?php

namespace App\Providers\Filament;

use App\Filament\Pages\ApiTokens;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Joaopaulolndev\FilamentEditProfile\FilamentEditProfilePlugin;
use Joaopaulolndev\FilamentEditProfile\Pages\EditProfilePage;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // One "Continue with …" button per active authentication source,
            // under the password form. Rendered from a hook rather than a
            // login page subclass, so the stock page stays stock.
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): ViewContract => view('filament.auth.sso-buttons'),
            )
            // Also the target of the link `php artisan admin:create` prints,
            // which is how the first account gets a password.
            ->passwordReset()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            // Filament's stock account and info widgets are deliberately absent;
            // the dashboard carries only the app's own widgets, which discovery
            // registers for us.
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            // Roles and permissions, plus the Roles resource for managing them.
            // Permissions are generated from the panel's own entities by
            // `php artisan shield:generate --all`.
            // Grouped with the Users resource, which manages who holds them.
            ->plugin(FilamentShieldPlugin::make()->navigationGroup('Access Management'))
            // Self-service profile page, reached from the user menu rather
            // than the sidebar. Account deletion stays off: accounts are
            // provisioned and removed by admins here.
            ->plugin(
                FilamentEditProfilePlugin::make()
                    ->shouldRegisterNavigation(false)
                    ->shouldShowDeleteAccountForm(false)
            )
            ->userMenuItems([
                'profile' => MenuItem::make()
                    ->label(fn (): string => auth()->user()->name)
                    ->url(fn (): string => EditProfilePage::getUrl())
                    ->icon('heroicon-m-user-circle'),
                // Personal Composer credentials, self-service for every
                // panel user — which is why it lives here and not in the
                // sidebar with the admin-managed resources.
                'api-tokens' => MenuItem::make()
                    ->label('API tokens')
                    ->url(fn (): string => ApiTokens::getUrl())
                    ->icon('heroicon-m-key'),
            ])
            // The bell, where a package's own releases and failed syncs land.
            // Syncing happens on a queue in response to a push, so there is
            // often nobody watching the page it happened on.
            ->databaseNotifications()
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
