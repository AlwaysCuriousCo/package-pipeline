<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\Sources\Actions\ConnectGitHubAction;
use App\Models\Download;
use App\Models\Package;
use App\Models\Source;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;

/**
 * The first run, as three steps on the page a new install lands on: connect
 * the GitHub account packages live in, import them, install one.
 *
 * A dashboard of zeros says nothing about what to do next, and everything
 * the steps link to already existed — it was only scattered across Sources
 * and Packages for somebody who did not know to look there. Each step ticks
 * itself off from the registry's own state, so there is nothing to dismiss:
 * the card goes away once a package has been installed from here, which is
 * the moment the registry has done its job once. Anybody done with it sooner
 * can dismiss it for themselves.
 */
class GettingStarted extends Widget implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    protected string $view = 'filament.widgets.getting-started';

    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->can('viewAny', Source::class)
            && $user->getting_started_dismissed_at === null
            && ! Download::query()->exists();
    }

    /**
     * Put the card away for this user, on every browser they sign in from.
     * Nothing brings it back: everything it links to is in the navigation.
     */
    public function dismissAction(): Action
    {
        return Action::make('dismiss')
            ->iconButton()
            ->icon(Heroicon::OutlinedXMark)
            ->color('gray')
            ->tooltip('Dismiss')
            ->action(function (): void {
                $user = auth()->user();

                if ($user instanceof User) {
                    $user->forceFill(['getting_started_dismissed_at' => now()])->save();
                }

                Notification::make()->success()->title('Getting started dismissed')->send();

                // The dashboard decides which widgets to render; reloading it
                // is what takes this one off the page.
                $this->redirect(Dashboard::getUrl());
            });
    }

    public function connectGithubAction(): Action
    {
        return ConnectGitHubAction::make();
    }

    public function importPackagesAction(): Action
    {
        return Action::make('importPackages')
            ->label('Import packages')
            ->icon(Heroicon::OutlinedCloudArrowDown)
            ->url(PackageResource::getUrl('index', ['action' => 'importFromSource']));
    }

    public function installAction(): Action
    {
        return Action::make('install')
            ->label('Open its install steps')
            ->icon(Heroicon::OutlinedCommandLine)
            ->url(fn (): ?string => ($package = $this->firstPackage())
                ? PackageResource::getUrl('view', ['record' => $package])
                : null);
    }

    /**
     * @return list<array{key: string, title: string, description: string, done: bool, available: bool}>
     */
    public function steps(): array
    {
        $connected = Source::query()->whereNotNull('connected_at')->exists();
        $package = $this->firstPackage();

        return [
            [
                'key' => 'connectGithub',
                'title' => 'Connect GitHub',
                'description' => 'The account your packages live in. The first connection also registers this registry\'s GitHub App, once.',
                'done' => $connected,
                'available' => true,
            ],
            [
                'key' => 'importPackages',
                'title' => 'Import your packages',
                'description' => 'Repositories with a composer.json are picked out and ticked for you; each one syncs its tags and branches.',
                'done' => $package !== null,
                'available' => $connected,
            ],
            [
                'key' => 'install',
                'title' => 'Install one',
                'description' => $package === null
                    ? 'Each package page has the composer commands, and a read token for a private repository.'
                    : "{$package->name}'s page has the composer commands, and a read token for a private repository.",
                'done' => false,
                'available' => $package !== null,
            ],
        ];
    }

    private function firstPackage(): ?Package
    {
        return Package::query()->oldest('id')->first();
    }
}
