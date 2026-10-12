<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RunsInstallationTests;
use App\Filament\Resources\Packages\Actions\ImportFromSourceAction;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\Packages\Schemas\PackageInfolist;
use App\Filament\Resources\Sources\Actions\ConnectGitHubAction;
use App\Filament\Widgets\RegistryTotals;
use App\Models\Download;
use App\Models\Package;
use App\Models\Source;
use App\Models\User;
use App\Services\InstallationTest;
use App\Services\InstallationTestStages;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

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
 *
 * @phpstan-type PackageSyncStatus array{package: Package, state: string, hasBatch: bool, running: bool, discovering: bool, imported: int, total: int, failed: int, progress: int, versions: int, error: string, awaitingReason: bool}
 *
 * @property-read Schema $importForm The inline step-2 schema resolved by Filament.
 * @property-read Schema $installInstructions The reused package install card.
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

    public const PACKAGES_KEY = 'onboarding.packages';

    public const SYNC_CONTINUED_KEY = 'onboarding.sync_continued';

    protected static ?string $slug = 'get-started/repository';

    protected static ?string $title = 'Repository';

    protected ?string $subheading = 'Connect GitHub, choose packages, let them sync, and install one.';

    /**
     * Whether the installation test run on this page passed, once one has run.
     */
    public ?bool $passed = null;

    #[Locked]
    public bool $testStarted = false;

    #[Locked]
    public string $testVisit;

    public bool $installationRunning = false;

    public ?int $installRepository = null;

    public ?string $plainTextToken = null;

    public ?string $tokenUsername = null;

    /** @var array<string, mixed> */
    public array $importData = [];

    public int $mountedStep;

    public int $syncWaitStartedAt;

    public bool $syncWaitExpired = false;

    public function mount(): void
    {
        $this->testVisit = (string) Str::uuid();
        $this->mountedStep = static::currentStep();
        $this->syncWaitStartedAt = now()->timestamp;

        if ($this->mountedStep === 2) {
            $source = Source::query()->connected()->find(request()->integer('source'));
            if (! $source instanceof Source && Source::query()->connected()->count() === 1) {
                $source = Source::query()->connected()->first();
            }
            $this->importForm->fill(ImportFromSourceAction::defaultData($source?->id));
        }
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('viewAny', Source::class);
    }

    /**
     * Opened from the Get started checklist, which is what the navigation
     * shows.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        return static::complete() ? 'Complete' : static::currentStep().'/'.count(static::progress());
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
        $statuses = self::packageStatuses();
        $imported = $statuses !== [];
        $states = array_column($statuses, 'state');
        $allReady = $imported && array_unique($states) === ['ready'];
        $continued = session(self::SYNC_CONTINUED_KEY) === array_map(fn (array $row): int => $row['package']->id, $statuses);
        $synced = $allReady || ($imported && $continued && ! in_array('syncing', $states, true)
            && in_array('ready', $states, true));

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
        return self::onboardingPackages()->first(fn (Package $package): bool => $package->versions_count > 0);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Wizard::make([
                Step::make('Connect GitHub')
                    ->key('connect')
                    ->icon(Heroicon::OutlinedLink)
                    ->schema([
                        Text::make('This registry serves PHP packages from your GitHub repositories to your projects. Start by connecting the GitHub account, personal or organisation, that holds those repositories.'),
                        Text::make('The connection is a GitHub App: a limited, read-only identity that GitHub creates for this registry the first time. Installing it on an account lets that account decide which repositories the registry may read. It can never change them.'),
                        Text::make('On GitHub, choose ‘Only select repositories’ and pick the ones to serve. GitHub asks you to approve the account and repository access, then brings you back here.'),
                        Actions::make([$this->connectAction()])->key('actions'),
                    ]),
                Step::make('Choose packages')
                    ->key('import')
                    ->icon(Heroicon::OutlinedCloudArrowDown)
                    ->schema([
                        Text::make('A package is a reusable PHP library that Composer, PHP\'s package manager, can add to your projects. The repositories you pick are imported as packages here.'),
                        Text::make('Likely packages are pre-ticked because their composer.json (the file that describes a PHP project) says they are a library rather than an application.'),
                        EmbeddedSchema::make('importForm'),
                        Actions::make([$this->importAction()])->key('actions'),
                    ]),
                Step::make('First sync')
                    ->key('sync')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->schema([
                        View::make('filament.pages.get-started.sync')
                            ->viewData(fn (): array => [
                                'mountedStep' => $this->mountedStep,
                                'status' => $this->syncStatus(),
                                'allSettled' => ! in_array('syncing', array_column($this->syncStatus(), 'state'), true)
                                    && ! in_array(true, array_column($this->syncStatus(), 'awaitingReason'), true),
                                'expired' => $this->syncWaitExpired,
                            ]),
                    ]),
                Step::make('Test installation')
                    ->key('test')
                    ->icon(Heroicon::OutlinedCommandLine)
                    ->schema([
                        View::make('filament.pages.get-started.test')
                            ->viewData(fn (): array => [
                                'autoRun' => $this->mountedStep === 4 && ! $this->testStarted,
                                'package' => static::installablePackage(),
                                'status' => $this->installationStatus(),
                                'lines' => $this->installationTranscript,
                            ]),
                        View::make('filament.pages.get-started.done')
                            ->viewData(fn (): array => ['package' => static::installablePackage(), 'packages' => $this->syncStatus()])
                            ->visible(fn (): bool => $this->passed === true),
                        EmbeddedSchema::make('installInstructions')
                            ->visible(fn (): bool => $this->passed === true),
                        Actions::make([
                            $this->runTestAction(),
                            ImportFromSourceAction::make()->label('Add more packages')->visible(fn (): bool => $this->passed === true && auth()->user()?->can('create', Package::class)),
                            Action::make('finish')->label('Finish')->color('primary')->url(Dashboard::getUrl())->visible(fn (): bool => $this->passed === true),
                        ])->key('actions'),
                    ]),
            ])
                ->key('onboarding')
                ->startOnStep(fn (): int => static::currentStep())
                // Steps follow the registry, not the buttons: each one moves
                // on by itself once what it asks for is done.
                ->nextAction(fn (Action $action): Action => $action->hidden()->extraAttributes(['class' => 'hidden']))
                ->previousAction(fn (Action $action): Action => $action->hidden()->extraAttributes(['class' => 'hidden'])),
        ]);
    }

    public function importForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('importData')
            ->components($this->mountedStep === 2 ? ImportFromSourceAction::pickerFields(inline: true) : []);
    }

    public function installInstructions(Schema $schema): Schema
    {
        return $schema->record(static::installablePackage())
            ->components($this->passed === true ? [PackageInfolist::installSection()] : []);
    }

    public function autoRunTest(): void
    {
        $this->runOnboardingTest();
    }

    public function retryTest(): void
    {
        if ($this->passed === false) {
            $this->runOnboardingTest(retry: true);
        }
    }

    private function runOnboardingTest(bool $retry = false): void
    {
        if ($this->mountedStep !== 4 || ($package = static::installablePackage()) === null) {
            return;
        }
        $key = 'onboarding-test:'.auth()->id().':'.$this->testVisit;
        $lock = Cache::lock($key.':running', InstallationTest::TIMEOUT + 30);
        if (! $lock->get()) {
            return;
        }
        try {
            if (! $retry && ($this->testStarted || ! Cache::add($key.':started', true, now()->addHour()))) {
                return;
            }
            $this->testStarted = true;
            $this->installationRunning = true;
            $this->installRepository = $package->repository_id;
            $this->passed = $this->runInstallationTest($package);
            if ($this->passed) {
                RegistryTotals::forget(auth()->user());
            }
        } finally {
            $this->installationRunning = false;
            $lock->release();
        }
    }

    /** @return array{stages: list<array{label: string, state: string}>, passed: ?bool, failure: ?string, hint: ?string} */
    public function installationStatus(): array
    {
        return InstallationTestStages::fromTranscript($this->installationTranscript, static::installablePackage()?->composerRepository->public ?? false);
    }

    protected function installationTestLineReported(): void
    {
        $this->stream(name: 'installation-status', replace: true, content: view('filament.pages.get-started.test-status', ['status' => $this->installationStatus()])->render());
    }

    /** @return Collection<int, Package> */
    private static function onboardingPackages(): Collection
    {
        return Package::query()
            ->when(session()->has(self::PACKAGES_KEY), fn ($query) => $query->whereIn('id', session(self::PACKAGES_KEY, [])))
            ->withCount('versions')->oldest('id')->get();
    }

    /** @return list<PackageSyncStatus> */
    private static function packageStatuses(): array
    {
        return self::onboardingPackages()->map(fn (Package $package): array => self::packageStatus($package))->all();
    }

    /** @return PackageSyncStatus */
    private static function packageStatus(Package $package): array
    {
        $batch = $package->syncBatch();
        $running = $package->syncRunning();
        $versions = $package->versions_count ?? $package->versions()->count();
        $error = (string) $package->sync_error;
        $state = match (true) {
            $running => 'syncing',
            $versions > 0 => 'ready',
            str_starts_with($error, 'No installable versions found:') => 'not a package',
            filled($error), $batch !== null => 'failed',
            default => 'syncing',
        };

        return [
            'package' => $package,
            'state' => $state,
            'hasBatch' => $batch !== null,
            'running' => $running,
            'discovering' => $batch !== null && $batch->totalJobs <= 1,
            'imported' => $batch === null ? 0 : max(0, $batch->processedJobs() - 1),
            'total' => $batch === null ? 0 : max(0, $batch->totalJobs - 1),
            'failed' => $batch === null ? 0 : $batch->failedJobs,
            'progress' => $batch?->progress() ?? 0,
            'versions' => $versions,
            'error' => Str::limit($error ?: 'No versions are ready to install yet. Open its page to sync again.', 180),
            // A batch can finish a moment before its last job records why
            // nothing was imported; the step keeps polling until it has.
            'awaitingReason' => $state === 'failed' && $error === '',
        ];
    }

    /** @return list<PackageSyncStatus> */
    public function syncStatus(): array
    {
        return self::packageStatuses();
    }

    public function removePackage(int $id): void
    {
        abort_unless($this->mountedStep === 3 && in_array($id, session(self::PACKAGES_KEY, []), true), 403);
        $package = Package::query()->findOrFail($id);
        $this->authorize('delete', $package);
        abort_unless(in_array(self::packageStatus($package)['state'], ['not a package', 'failed'], true), 403);
        $package->delete();
        session()->put(self::PACKAGES_KEY, array_values(array_diff(session(self::PACKAGES_KEY, []), [$id])));
        $this->checkSync();
    }

    public function continueToInstallationTest(): void
    {
        $statuses = $this->syncStatus();
        $states = array_column($statuses, 'state');
        abort_unless($this->mountedStep === 3 && ! in_array('syncing', $states, true) && in_array('ready', $states, true), 403);
        session()->put(self::SYNC_CONTINUED_KEY, array_map(fn (array $row): int => $row['package']->id, $statuses));
        $this->redirect(static::getUrl());
    }

    /**
     * Polled only by the sync step: every import must settle before the
     * test can run, so the page opens again on it.
     */
    public function checkSync(): void
    {
        if (static::currentStep() !== $this->mountedStep) {
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
        return Action::make('importPackages')
            ->label('Import packages')
            ->icon(Heroicon::OutlinedCloudArrowDown)
            ->color('primary')
            ->authorize('create', Package::class)
            ->visible(fn (): bool => $this->mountedStep === 2)
            ->action(function (): void {
                $data = $this->importForm->getState();
                $source = Source::query()->findOrFail($data['source_id']);
                abort_unless(auth()->user()?->can('view', $source), 403);
                if ($source->usesInstallation()) {
                    $data['create_webhook'] = true;
                }
                [$created] = ImportFromSourceAction::import($data);
                session()->put(self::PACKAGES_KEY, array_values(array_unique([
                    ...session(self::PACKAGES_KEY, []),
                    ...array_map(fn (Package $package): int => $package->id, $created),
                ])));
                if (static::currentStep() > 2) {
                    $this->redirect(static::getUrl());
                }
            });
    }

    private function runTestAction(): Action
    {
        return Action::make('runTest')
            ->label('Run again')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('gray')
            ->visible(fn (): bool => $this->passed === false && ! $this->installationRunning)
            ->action(fn () => $this->retryTest());
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
