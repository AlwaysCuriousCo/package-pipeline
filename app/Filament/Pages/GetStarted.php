<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RunsInstallationTests;
use App\Filament\Resources\Packages\Actions\ImportFromSourceAction;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\Sources\Actions\ConnectGitHubAction;
use App\Models\Download;
use App\Models\Package;
use App\Models\Source;
use App\Models\User;
use App\Services\InstallationTest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The first run on one page: connect the GitHub account packages live in,
 * import them, wait for their first sync, and install one the way Composer
 * would.
 *
 * Two of the steps leave for GitHub and come back, so the wizard keeps no
 * state of its own. The step it opens on is read from the registry — is an
 * account connected, is a package imported, does one have versions — which
 * is also what the dashboard's card counts, and the step is deliberately not
 * kept in the query string, where a stale ?step could skip one that is not
 * done. Moving on, likewise, is a fresh visit to the page rather than the
 * wizard's Next button: the page then opens on whatever step is now true.
 */
class GetStarted extends Page
{
    use RunsInstallationTests;

    /**
     * Set when the GitHub App is registered from here, so the registration
     * hands the install it carries on to back to the wizard. The install then
     * carries it on its pending connection, so only that connection's callback
     * honours it.
     */
    public const RETURN_KEY = 'onboarding.return';

    protected static ?string $slug = 'get-started';

    protected static ?string $title = 'Get started';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRocketLaunch;

    protected static ?int $navigationSort = -10;

    /**
     * Whether the installation test run on this page passed, once one has run.
     */
    public ?bool $passed = null;

    public int $syncWaitStartedAt;

    public bool $syncWaitExpired = false;

    public function mount(): void
    {
        $this->syncWaitStartedAt = now()->timestamp;
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('viewAny', Source::class);
    }

    /**
     * In the navigation until the registry has done its job once — a package
     * installed, or an installation test passed — so a first run left half
     * way is a click away from anywhere. Later packages are added from
     * Packages → Add packages.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && ! static::complete();
    }

    public static function getNavigationBadge(): ?string
    {
        return static::currentStep().'/'.count(static::progress());
    }

    /**
     * Whether a package has been installed, or an installation test passed.
     */
    public static function complete(): bool
    {
        return Download::query()->exists() || InstallationTest::hasPassed();
    }

    /**
     * The steps in order, each with whether the registry already has it done.
     *
     * @return list<array{title: string, done: bool}>
     */
    public static function progress(): array
    {
        $connected = Source::query()->whereNotNull('connected_at')->exists();
        $imported = Package::query()->exists();
        $synced = static::installablePackage() !== null;

        return [
            ['title' => 'Connect GitHub', 'done' => $connected],
            ['title' => 'Choose packages', 'done' => $imported],
            ['title' => 'First sync', 'done' => $synced],
            ['title' => 'Test installation', 'done' => InstallationTest::hasPassed()],
        ];
    }

    /**
     * The step to open on, from 1: the first one not done, or the last.
     */
    public static function currentStep(): int
    {
        foreach (static::progress() as $index => $step) {
            if (! $step['done']) {
                return $index + 1;
            }
        }

        return count(static::progress());
    }

    /**
     * The first package with a version to install.
     */
    public static function installablePackage(): ?Package
    {
        return Package::query()->whereHas('versions')->oldest('id')->first();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Wizard::make([
                Step::make('Connect GitHub')
                    ->key('connect')
                    ->icon(Heroicon::OutlinedLink)
                    ->schema([
                        Text::make('The account your packages live in. The first connection also registers this registry\'s GitHub App with GitHub, once; then GitHub asks which repositories it may read, and brings you back here.'),
                        Text::make('On GitHub, choose ‘Only select repositories’ and pick the ones to serve. GitHub asks you to approve the account and repository access.'),
                        Actions::make([$this->connectAction()])->key('actions'),
                    ]),
                Step::make('Choose packages')
                    ->key('import')
                    ->icon(Heroicon::OutlinedCloudArrowDown)
                    ->schema([
                        Text::make('Repositories with a composer.json are picked out and ticked for you. Each one becomes a package, and its tags and branches sync in the background.'),
                        Actions::make([$this->importAction()])->key('actions'),
                    ]),
                Step::make('First sync')
                    ->key('sync')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->schema([
                        View::make('filament.pages.get-started.sync')
                            ->viewData(fn (): array => [
                                'package' => Package::query()->oldest('id')->first(),
                                'waiting' => static::currentStep() === 3,
                                'expired' => $this->syncWaitExpired,
                            ]),
                    ]),
                Step::make('Test installation')
                    ->key('test')
                    ->icon(Heroicon::OutlinedCommandLine)
                    ->schema([
                        Text::make(fn (): string => 'Installs '.(static::installablePackage()->name ?? 'a package').' the way Composer would, through this registry\'s public address, with a token that exists for this run only, and shows each request.'),
                        View::make('filament.terminal')
                            ->viewData(fn (): array => [
                                'lines' => $this->installationTranscript,
                                'stream' => 'installation-test',
                                'idle' => 'Run test makes the requests Composer makes, and shows each one here as it happens.',
                            ]),
                        Actions::make([$this->runTestAction()])->key('actions'),
                        View::make('filament.pages.get-started.done')
                            ->viewData(fn (): array => ['package' => static::installablePackage()])
                            ->visible(fn (): bool => $this->passed ?? InstallationTest::hasPassed()),
                    ]),
            ])
                ->key('onboarding')
                ->startOnStep(fn (): int => static::currentStep())
                // Steps follow the registry, not the buttons: each one moves
                // on by itself once what it asks for is done.
                ->nextAction(fn (Action $action): Action => $action->hidden()->extraAttributes(['style' => 'display: none']))
                ->previousAction(fn (Action $action): Action => $action->hidden()->extraAttributes(['style' => 'display: none'])),
        ]);
    }

    /**
     * Polled by the sync step while it waits: the first versions mean the
     * test can run, so the page opens again on it.
     */
    public function checkSync(): void
    {
        if (static::currentStep() > 3) {
            $this->redirect(static::getUrl());

            return;
        }

        $this->syncWaitExpired = now()->timestamp - $this->syncWaitStartedAt >= 300;
    }

    public function recheckSync(): void
    {
        $this->syncWaitStartedAt = now()->timestamp;
        $this->syncWaitExpired = false;
        $this->checkSync();
    }

    /**
     * The sources list's own Connect button, marking the round trip as this
     * page's before it leaves for GitHub.
     */
    private function connectAction(): Action
    {
        // With an app already registered the button is a link to the
        // install, which asks to come back here.
        if (ConnectGitHubAction::isAvailable()) {
            return ConnectGitHubAction::make()->url(route('sources.connect.new', ['onboarding' => 1]));
        }

        // With none yet it registers one first, and the registration hands
        // the request on to the install it carries on to.
        return ConnectGitHubAction::make()->before(fn () => session()->put(self::RETURN_KEY, true));
    }

    private function importAction(): Action
    {
        // The account was connected a step ago, so there is no access to
        // grant yet; and the wizard, not the package, is where this goes on.
        return ImportFromSourceAction::make(accessStep: false, openPackage: false, onboarding: true)
            ->color('primary')
            ->after(function (): void {
                if (static::currentStep() > 2) {
                    $this->redirect(static::getUrl());
                }
            });
    }

    private function runTestAction(): Action
    {
        return Action::make('runTest')
            ->label('Run test')
            ->icon(Heroicon::OutlinedPlay)
            ->visible(fn (): bool => static::installablePackage() !== null)
            ->action(function (): void {
                $package = static::installablePackage();

                if ($package !== null) {
                    $this->passed = $this->runInstallationTest($package);
                }
            });
    }

    /**
     * Where the finished run points next: the package, whose page has the
     * commands for a real project.
     */
    public static function packageUrl(Package $package): string
    {
        return PackageResource::getUrl('view', ['record' => $package]);
    }
}
