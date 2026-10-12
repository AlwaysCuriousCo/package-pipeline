<?php

namespace App\Filament\Resources\GitHubApps\Pages;

use App\Enums\GitHubAppOwner;
use App\Filament\Concerns\HidesBreadcrumbs;
use App\Filament\Resources\GitHubApps\Actions\RegisterGitHubAppAction;
use App\Filament\Resources\GitHubApps\GitHubAppResource;
use App\Filament\Resources\Sources\SourceResource;
use App\Http\Controllers\GitHubAppRegistrationController;
use App\Models\GitHubAppRegistration;
use App\Models\Source;
use App\Services\GitHub\GitHubApp;
use App\Services\GitHub\GitHubAppManifest;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Js;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Register the GitHub App from the panel, instead of by hand.
 *
 * By hand is a table of fields copied into GitHub, a webhook secret invented
 * with openssl, a .pem downloaded and opened and pasted, and three secrets on
 * the deployment followed by a redeploy. Here it is a button: a user or
 * organisation account takes a manifest, and GitHub hands the credentials
 * straight back. An enterprise cannot take a manifest, so its form is opened
 * prefilled, the App ID and .pem are brought back through an upload, and the
 * registry gives the app's webhook its secret itself.
 *
 * The environment still wins. A deployment that sets GITHUB_APP_ID sees this
 * page read-only, and nothing about it changes.
 *
 * @see docs/github-app.md
 */
class ManageGitHubApp extends Page
{
    use HidesBreadcrumbs;

    protected static string $resource = GitHubAppResource::class;

    protected static ?string $title = 'GitHub App';

    /**
     * The session key holding an enterprise registration in progress: which
     * enterprise, the app's name, and the steps already done. Kept until the
     * credentials come back, so a reload or a detour through GitHub picks up
     * where it left off.
     */
    public const ENTERPRISE_KEY = 'github-app.enterprise';

    /**
     * The session key holding the last registration that failed: what failed,
     * what GitHub said in full, and what to do about it. A failure here often
     * leaves an app behind on GitHub that needs deleting, which is not
     * something to learn from a toast that faded while nobody was looking, so
     * it stays on the page until the next attempt or until it is dismissed.
     */
    public const FAILURE_KEY = 'github-app.failure';

    /**
     * Record a failed registration, for the page to show until the next
     * attempt, and say so now in a notification that also stays put.
     */
    public static function fail(string $title, string $detail, string $next): void
    {
        session()->put(self::FAILURE_KEY, ['title' => $title, 'detail' => $detail, 'next' => $next]);

        Notification::make()->danger()->title($title)->body($detail)->persistent()->send();
    }

    public static function canAccess(array $parameters = []): bool
    {
        return auth()->user()?->can('viewAny', GitHubAppRegistration::class) ?? false;
    }

    public function content(Schema $schema): Schema
    {
        $app = app(GitHubApp::class);

        return $schema->components([
            ...($this->failure() === null ? [] : [$this->failureSection($this->failure())]),
            match (true) {
                $app->configuredFromEnvironment() => $this->environmentSection(),
                $app->registration() !== null => $this->registeredSection($app->registration()),
                $this->pendingEnterprise() !== null => $this->enterpriseSection($this->pendingEnterprise()),
                default => $this->unregisteredSection(),
            },
        ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('sources')
                ->label('Sources')
                ->icon(Heroicon::OutlinedCloud)
                ->color('gray')
                ->url(SourceResource::getUrl('index')),
        ];
    }

    /**
     * @param  array{title: string, detail: string, next: string}  $failure
     */
    private function failureSection(array $failure): Section
    {
        return Section::make($failure['title'])
            ->key('failure')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->iconColor('danger')
            ->headerActions([
                Action::make('dismissFailure')
                    ->label('Dismiss')
                    ->color('gray')
                    ->action(function (): void {
                        session()->forget(self::FAILURE_KEY);

                        $this->reload();
                    }),
            ])
            ->schema([
                TextEntry::make('failure_detail')
                    ->hiddenLabel()
                    ->state($failure['detail'])
                    ->color('danger'),
                TextEntry::make('failure_next')
                    ->label('What to do next')
                    ->state($failure['next']),
            ]);
    }

    private function environmentSection(): Section
    {
        return Section::make('Configured from the environment')
            ->icon(Heroicon::OutlinedLockClosed)
            ->description('GITHUB_APP_ID and GITHUB_APP_PRIVATE_KEY are set on this deployment, so they decide which app is used and nothing here can change it. Unset them to register an app from this page instead.')
            ->schema([
                TextEntry::make('app_id')
                    ->label('App ID')
                    ->state(fn (): string => (string) (config('services.github.app.id') ?: '—')),
            ]);
    }

    private function registeredSection(GitHubAppRegistration $registration): Section
    {
        return Section::make($registration->name)
            ->icon(Heroicon::OutlinedCheckCircle)
            ->iconColor('success')
            ->description('Registered from this page. Sources connect by installing it on an account.')
            ->columns(3)
            ->headerActions([$this->configureWebhookAction(), $this->forgetAction()])
            ->schema([
                TextEntry::make('owner')
                    ->label('Owner')
                    ->state($registration->owner)
                    ->helperText($registration->owner_type->getLabel()),
                TextEntry::make('app_id')
                    ->label('App ID')
                    ->state($registration->app_id),
                TextEntry::make('slug')
                    ->label('Settings on GitHub')
                    ->state($registration->slug)
                    ->url($registration->owner_type->settingsUrl($registration->owner, $registration->slug), shouldOpenInNewTab: true)
                    ->color('primary'),
            ]);
    }

    /**
     * The enterprise round trip as numbered steps, each ticked off as it is
     * done, so leaving for GitHub and coming back never means guessing where
     * you were. Steps 1 and 2 happen on GitHub's side and are ticked when
     * their button is used; step 3 is the one that saves. The webhook secret
     * that GitHub's form cannot be given is set by the registry itself once
     * the key is verified, so there is nothing to copy across.
     *
     * @param  array{owner: string, name: string, public?: bool, done?: list<string>}  $pending
     */
    private function enterpriseSection(array $pending): Section
    {
        $done = $pending['done'] ?? [];
        $prefill = (new GitHubAppManifest($pending['name'], (bool) ($pending['public'] ?? false)))->prefillUrl(GitHubAppOwner::Enterprise, $pending['owner']);

        $step = fn (string $key, string $heading): Section => Section::make($heading)
            ->key("step-{$key}")
            ->compact()
            ->icon(in_array($key, $done, true) ? Heroicon::OutlinedCheckCircle : null)
            ->iconColor('success');

        return Section::make("Register {$pending['name']} on {$pending['owner']}")
            ->icon(Heroicon::OutlinedBuildingOffice2)
            ->description('GitHub does not take app manifests for enterprises, so this one is three steps, two of them on GitHub. Each is ticked off here as you go.')
            ->headerActions([
                Action::make('cancel')
                    ->label('Start over')
                    ->color('gray')
                    ->action(function (): void {
                        session()->forget(self::ENTERPRISE_KEY);

                        $this->reload();
                    }),
            ])
            ->schema([
                $step('opened', '1. Create the app on GitHub')
                    ->description("Opens {$pending['owner']}'s New GitHub App form with everything filled in. Leave the webhook secret empty — this registry sets it — and press Create GitHub App at the bottom of the form.")
                    ->headerActions([
                        Action::make('openGitHub')
                            ->label('Open GitHub')
                            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                            ->alpineClickHandler('window.open('.Js::from($prefill).', \'_blank\'); $wire.markEnterpriseStep(\'opened\')'),
                    ]),
                $step('key', '2. Generate a private key')
                    ->description('Creating the app lands you on its settings page, General tab. Scroll to the bottom, to Private keys, and press Generate a private key: your browser downloads a .pem file. The App ID is at the top of the same page, under About.')
                    ->headerActions([
                        Action::make('haveKey')
                            ->label('I have the .pem')
                            ->color('gray')
                            // Called on the component rather than as an action, so
                            // the page re-renders with the tick: an action runs
                            // after the schema it belongs to has been built.
                            ->alpineClickHandler('$wire.markEnterpriseStep(\'key\')'),
                    ]),
                $step('complete', '3. Enter the App ID and upload the key')
                    ->description('GitHub is asked whether the key belongs to that app before anything is saved, and the app\'s webhook is given its secret. Then it is on to installing the app on an account.')
                    ->headerActions([$this->completeAction()]),
            ]);
    }

    /**
     * Tick off one of the enterprise steps that happen on GitHub's side.
     */
    public function markEnterpriseStep(string $step): void
    {
        $pending = $this->pendingEnterprise();

        if ($pending === null || ! in_array($step, ['opened', 'key'], true)) {
            return;
        }

        $pending['done'] = array_values(array_unique([...($pending['done'] ?? []), $step]));

        session()->put(self::ENTERPRISE_KEY, $pending);
    }

    private function unregisteredSection(): Section
    {
        return Section::make('No GitHub App yet')
            ->icon(Heroicon::OutlinedCommandLine)
            ->description('Sources authenticate as a GitHub App installed on each account. Register one for this registry — GitHub creates it with the right permissions, webhook and callback, and the credentials come straight back here.')
            ->headerActions([$this->registerAction()]);
    }

    public function registerAction(): Action
    {
        return RegisterGitHubAppAction::make()
            ->visible(fn (): bool => ! app(GitHubApp::class)->isConfigured()
                && ! app(GitHubApp::class)->configuredFromEnvironment()
                && $this->pendingEnterprise() === null);
    }

    /**
     * The second half of an enterprise registration: the App ID and key that
     * GitHub would have handed back from a manifest, brought back by hand.
     * The key arrives as a file so it is never pasted, and never kept: it is
     * read from the upload and the upload deleted.
     */
    public function completeAction(): Action
    {
        return Action::make('complete')
            ->label('Enter App ID and key')
            ->icon(Heroicon::OutlinedKey)
            ->authorize('create', GitHubAppRegistration::class)
            ->visible(fn (): bool => $this->pendingEnterprise() !== null && ! app(GitHubApp::class)->isConfigured())
            ->modalHeading('Finish registering')
            ->modalDescription('Both are on the app\'s settings page on GitHub: the App ID at the top, and the key under Private keys → Generate a private key, which downloads a .pem.')
            ->modalSubmitActionLabel('Verify and save')
            ->schema([
                TextInput::make('app_id')
                    ->label('App ID')
                    ->required()
                    ->integer(),
                FileUpload::make('private_key')
                    ->label('Private key (.pem)')
                    ->required()
                    ->storeFiles(false)
                    ->maxSize(16),
            ])
            ->action(function (array $data): void {
                $pending = $this->pendingEnterprise();

                if ($pending === null) {
                    return;
                }

                session()->forget(self::FAILURE_KEY);

                $upload = $data['private_key'];
                $upload = is_array($upload) ? reset($upload) : $upload;
                $key = $upload instanceof TemporaryUploadedFile ? (string) $upload->get() : '';

                if ($upload instanceof TemporaryUploadedFile) {
                    $upload->delete();
                }

                try {
                    $registration = app(GitHubApp::class)->register(
                        GitHubAppOwner::Enterprise,
                        $pending['owner'],
                        (string) $data['app_id'],
                        $key,
                        null,
                    );
                } catch (Throwable $exception) {
                    self::fail(
                        'The App ID and key were not saved',
                        $exception->getMessage(),
                        'Check the App ID at the top of the app\'s page on GitHub, and that the .pem was generated on that same app, then Enter App ID and key again.',
                    );

                    $this->reload();

                    return;
                }

                session()->forget(self::ENTERPRISE_KEY);

                // The app is registered whatever happens next; a webhook
                // without its secret is something to fix from this page, not
                // a reason to throw the registration away.
                try {
                    app(GitHubApp::class)->configureWebhook($registration);
                } catch (Throwable $exception) {
                    self::fail(
                        'The app is registered, but its webhook has no secret yet',
                        $exception->getMessage(),
                        'Use Configure webhook on this page to try again. Until it works, GitHub\'s deliveries cannot be verified, so packages sync on the hourly schedule instead of on push.',
                    );

                    $this->reload();

                    return;
                }

                // On to installing it, exactly as the manifest path goes, and
                // reported the same way once the install is done.
                session()->put(GitHubAppRegistrationController::REGISTERED_KEY, $registration->name);

                $this->redirect(route('sources.connect.new'));
            });
    }

    /**
     * Set the webhook secret again, for a registration whose first attempt
     * failed — the same call the enterprise round trip ends with.
     */
    public function configureWebhookAction(): Action
    {
        return Action::make('configureWebhook')
            ->label('Configure webhook')
            ->icon(Heroicon::OutlinedArrowPath)
            ->authorize('create', GitHubAppRegistration::class)
            ->visible(fn (): bool => ($registration = app(GitHubApp::class)->registration()) !== null
                && blank($registration->webhook_secret))
            ->action(function (): void {
                session()->forget(self::FAILURE_KEY);

                $registration = app(GitHubApp::class)->registration();

                if ($registration === null) {
                    return;
                }

                try {
                    app(GitHubApp::class)->configureWebhook($registration);
                } catch (Throwable $exception) {
                    self::fail(
                        'The webhook still has no secret',
                        $exception->getMessage(),
                        'Check the app\'s webhook on GitHub is switched on, then Configure webhook again.',
                    );

                    $this->reload();

                    return;
                }

                Notification::make()->success()->title('Webhook configured')->body('Pushes now sync their packages straight away.')->send();

                $this->reload();
            });
    }

    public function forgetAction(): Action
    {
        return Action::make('forget')
            ->label('Forget')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->authorize('delete', app(GitHubApp::class)->registration())
            ->visible(fn (): bool => app(GitHubApp::class)->registration() !== null)
            ->requiresConfirmation()
            ->modalHeading('Forget this GitHub App?')
            ->modalDescription(function (): string {
                $installed = Source::query()->whereNotNull('installation_id')->count();

                return ($installed === 0
                    ? 'No source is connected through it.'
                    : trans_choice('{1} 1 source is connected through it and will stop authenticating until an app is configured again.|[2,*] :count sources are connected through it and will stop authenticating until an app is configured again.', $installed)
                        .' The sources and their packages are kept.')
                    .' The app itself stays on GitHub — delete it there if it is no longer wanted.';
            })
            ->action(function (): void {
                app(GitHubApp::class)->forget();

                Notification::make()->success()->title('GitHub App forgotten')->send();

                $this->reload();
            });
    }

    /**
     * Load the page afresh after its state changed. Which section it shows is
     * decided when its schema is built, once per request, so an action that
     * registers or forgets an app would otherwise leave the old one on screen.
     */
    private function reload(): void
    {
        $this->redirect(static::getUrl());
    }

    /**
     * @return array{title: string, detail: string, next: string}|null
     */
    private function failure(): ?array
    {
        $failure = session(self::FAILURE_KEY);

        return is_array($failure) ? $failure : null;
    }

    /**
     * @return array{owner: string, name: string, public?: bool, done?: list<string>}|null
     */
    private function pendingEnterprise(): ?array
    {
        $pending = session(self::ENTERPRISE_KEY);

        return is_array($pending) ? $pending : null;
    }
}
