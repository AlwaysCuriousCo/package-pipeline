<?php

namespace App\Filament\Resources\GitHubApps\Actions;

use App\Enums\GitHubAppOwner;
use App\Filament\Resources\GitHubApps\Pages\ManageGitHubApp;
use App\Http\Controllers\GitHubAppRegistrationController;
use App\Models\GitHubAppRegistration;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RegisterGitHubAppAction
{
    /**
     * GitHub's own limit on an app's name.
     */
    private const MAX_NAME = 34;

    /**
     * Register this registry's GitHub App: who owns it, and its name.
     *
     * Offered on the app's own page, and as the first step of connecting an
     * account on an instance that has no app yet — so a first run never meets
     * a disabled button. Either way a user or organisation goes to GitHub with
     * a manifest and comes back to install the app; an enterprise goes to the
     * app page's step-by-step round trip, which ends in the same place.
     */
    public static function make(string $name = 'register'): Action
    {
        return Action::make($name)
            ->label('Register GitHub App')
            ->icon(Heroicon::OutlinedPlus)
            ->authorize('create', GitHubAppRegistration::class)
            ->modalHeading('Register a GitHub App')
            ->modalDescription('A GitHub App is how this registry reads repositories on GitHub. You create it once, for this registry. Connecting an account then installs the App there: the account chooses which repositories it may read.')
            ->modalSubmitActionLabel('Continue')
            ->schema([
                Radio::make('owner_type')
                    ->label('Who owns the App on GitHub?')
                    ->helperText('An organisation is usual: the App then outlives any one person\'s account.')
                    ->options(GitHubAppOwner::class)
                    ->descriptions([
                        'user' => 'Pick this if your packages belong to your personal GitHub account.',
                        'organization' => 'Pick this for a team’s GitHub organisation; its members can manage the App when you leave.',
                        'enterprise' => 'Pick this if your company manages several organisations under one GitHub enterprise. You need permission to create enterprise Apps.',
                    ])
                    ->default(GitHubAppOwner::Organization->value)
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        if (self::owner($state) === GitHubAppOwner::Enterprise) {
                            $set('public', 0);
                        }
                    }),
                TextInput::make('owner')
                    ->label(fn (Get $get): string => self::owner($get('owner_type')) === GitHubAppOwner::Enterprise ? 'Enterprise slug' : 'Organisation')
                    ->helperText(fn (Get $get): string => self::owner($get('owner_type')) === GitHubAppOwner::Enterprise
                        ? 'As in github.com/enterprises/<slug>.'
                        : 'As in github.com/<organisation>.')
                    ->visible(fn (Get $get): bool => self::owner($get('owner_type')) !== GitHubAppOwner::User)
                    ->required()
                    ->alphaDash()
                    ->maxLength(100),
                Radio::make('public')
                    ->label('Who can install it?')
                    ->options(fn (Get $get): array => self::owner($get('owner_type')) === GitHubAppOwner::Enterprise
                        ? [0 => 'Only this enterprise\'s organisations']
                        : [0 => 'Only this account', 1 => 'Any account'])
                    ->descriptions(fn (Get $get): array => self::owner($get('owner_type')) === GitHubAppOwner::Enterprise
                        ? [0 => 'Any organisation in this enterprise can install it, and nothing outside it can. An enterprise App cannot be offered to other accounts.']
                        : [
                            0 => 'Pick this if every repository you will serve lives in the account that owns the App.',
                            1 => 'Pick this to serve repositories from other accounts or organisations too. Each one still installs the App and chooses its repositories.',
                        ])
                    ->helperText(fn (Get $get): string => (self::owner($get('owner_type')) === GitHubAppOwner::Enterprise
                        ? 'Installing the App gives this registry read access to the repositories an organisation picks.'
                        : 'Installing the App gives this registry read access to the repositories an account picks. “Only this account” is the default because it is the narrowest; you can change it later in the App\'s settings on GitHub.')
                        .' Neither choice makes your repositories or this registry public.')
                    ->default(0)
                    ->required()
                    ->rules(['boolean'])
                    ->rule(fn (Get $get) => Rule::in(self::owner($get('owner_type')) === GitHubAppOwner::Enterprise ? [0] : [0, 1])),
                TextInput::make('name')
                    ->label('App name')
                    ->default(fn (): string => self::defaultName())
                    ->helperText('Unique across GitHub. Name each environment\'s app after it, e.g. "Acme Packages (Staging)".')
                    ->required()
                    ->maxLength(self::MAX_NAME),
            ])
            // No return type: inside Livewire, redirect() hands back
            // Livewire's own Redirector rather than a RedirectResponse.
            ->action(function (array $data) {
                session()->forget(ManageGitHubApp::FAILURE_KEY);

                $owner = self::owner($data['owner_type']) ?? GitHubAppOwner::Organization;

                if (! $owner->supportsManifest()) {
                    session()->put(ManageGitHubApp::ENTERPRISE_KEY, [
                        'owner' => $data['owner'],
                        'name' => $data['name'],
                        'public' => (bool) $data['public'],
                        'done' => [],
                    ]);

                    return redirect(ManageGitHubApp::getUrl());
                }

                session()->put(GitHubAppRegistrationController::SESSION_KEY, [
                    'state' => Str::random(40),
                    'public' => (bool) $data['public'],
                    'owner_type' => $owner->value,
                    'owner' => $owner === GitHubAppOwner::User ? null : $data['owner'],
                    'name' => $data['name'],
                ]);

                return redirect()->route('github-app.manifest');
            });
    }

    /**
     * A name for the app that is likely to be free.
     *
     * App names are unique across all of GitHub, so the bare APP_NAME — the
     * same "Package Pipeline" on every install — is the one name certain to be
     * taken. The site's own name makes it this deployment's: the host when it
     * fits GitHub's 34 characters, otherwise the domain's own label
     * ("acme" in packages.acme.test).
     *
     * On a host of three or more labels that label may be a shared platform's
     * ("laravel" in *.laravel.cloud), which names no one's app, so the app's
     * own name, trimmed to fit, comes before it there.
     */
    public static function defaultName(): string
    {
        $name = (string) config('app.name');
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        $labels = explode('.', $host);
        $subdomain = count($labels) > 2;
        $site = $subdomain ? $labels[count($labels) - 2] : $labels[0];

        $candidates = $subdomain
            ? ["{$name} ({$host})", "{$name} ({$site})", Str::limit($name, self::MAX_NAME, ''), $site, $name]
            : ["{$name} ({$host})", "{$name} ({$site})", $site, $name];

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && mb_strlen($candidate) <= self::MAX_NAME) {
                return $candidate;
            }
        }

        return Str::limit($site, self::MAX_NAME, '');
    }

    /**
     * The owner picked in the form, which a Radio over an enum hands back as
     * the case once it has been cast and as its value before.
     */
    private static function owner(mixed $state): ?GitHubAppOwner
    {
        return $state instanceof GitHubAppOwner ? $state : GitHubAppOwner::tryFrom((string) $state);
    }
}
