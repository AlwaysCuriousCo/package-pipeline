<?php

namespace App\Filament\Resources\GitHubApps;

use App\Filament\Resources\GitHubApps\Pages\ManageGitHubApp;
use App\Models\GitHubAppRegistration;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;

/**
 * The GitHub App this registry authenticates as.
 *
 * A resource rather than a plain page so that Shield derives permissions for
 * it like any other record, and registering or forgetting the app is granted
 * on the Roles screen the way managing login providers is. There is only ever
 * one record, so the resource is a single page.
 */
class GitHubAppResource extends Resource
{
    protected static ?string $model = GitHubAppRegistration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static ?string $modelLabel = 'GitHub App';

    protected static ?string $slug = 'github-app';

    // Reached from the Sources list, where connecting an account needs it.
    protected static bool $shouldRegisterNavigation = false;

    public static function getPages(): array
    {
        return [
            'index' => ManageGitHubApp::route('/'),
        ];
    }
}
