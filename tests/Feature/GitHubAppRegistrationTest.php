<?php

namespace Tests\Feature;

use App\Enums\GitHubAppOwner;
use App\Filament\Pages\GetStarted;
use App\Filament\Resources\GitHubApps\Actions\RegisterGitHubAppAction;
use App\Filament\Resources\GitHubApps\Pages\ManageGitHubApp;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Http\Controllers\GitHubAppRegistrationController;
use App\Models\GitHubAppRegistration;
use App\Models\Source;
use App\Models\User;
use App\Services\GitHub\GitHubApp;
use App\Services\GitHub\GitHubAppManifest;
use Database\Factories\GitHubAppRegistrationFactory;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GitHubAppRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.github.app', [
            'id' => null,
            'private_key' => null,
            'slug' => null,
            'api_url' => 'https://api.github.com',
            'webhook_secret' => null,
        ]);

        $this->actingAs(User::factory()->superAdmin()->create());
    }

    /**
     * A manifest registration in progress, as the panel's register action
     * leaves it.
     */
    private function pending(GitHubAppOwner $owner = GitHubAppOwner::Organization, ?string $login = 'acme'): void
    {
        session()->put(GitHubAppRegistrationController::SESSION_KEY, [
            'state' => 'the-state',
            'owner_type' => $owner->value,
            'owner' => $login,
            'name' => 'Acme Packages',
        ]);
    }

    /**
     * GitHub's side of a manifest conversion: the new app's credentials, and
     * the app answering for itself when the key is checked.
     */
    /**
     * @param  array<string, mixed>|null  $refusal  GitHub's answer when it refuses the conversion
     */
    private function fakeConversion(int $status = 200, ?array $refusal = null): string
    {
        $pem = GitHubAppRegistrationFactory::privateKey();

        Http::fake([
            'api.github.com/app-manifests/the-code/conversions' => $refusal !== null ? Http::response($refusal, 422) : Http::response([
                'id' => 123456,
                'slug' => 'acme-packages',
                'name' => 'Acme Packages',
                'owner' => ['login' => 'acme'],
                'html_url' => 'https://github.com/apps/acme-packages',
                'pem' => $pem,
                'webhook_secret' => 'the-generated-secret',
                'client_id' => 'Iv1.abc',
                'client_secret' => 'never-stored',
            ], 201),
            'api.github.com/app' => $status === 200
                ? Http::response(['id' => 123456, 'slug' => 'acme-packages', 'name' => 'Acme Packages', 'html_url' => 'https://github.com/apps/acme-packages'])
                : Http::response(['message' => 'A JSON web token could not be decoded'], $status),
        ]);

        return $pem;
    }

    /**
     * The `iss` claim of the JWT a request was signed with: the app GitHub
     * would take it to be from.
     */
    private function issuer(Request $request): string
    {
        $jwt = substr($request->header('Authorization')[0], strlen('Bearer '));

        return json_decode(base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true)['iss'];
    }

    /**
     * The notifications queued for the next page the admin sees.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function notifications(): Collection
    {
        /** @var list<array<string, mixed>> $queued */
        $queued = session('filament.notifications', []);

        return collect($queued);
    }

    /**
     * The failure the page will show, which must also have been announced in
     * a notification that does not fade.
     *
     * @return array{title: string, detail: string, next: string}
     */
    private function assertFailedPersistently(string $title): array
    {
        $failure = session('github-app.failure');

        $this->assertSame($title, $failure['title'] ?? null);

        $notification = $this->notifications()->firstWhere('title', $title);

        $this->assertNotNull($notification, "No notification titled [{$title}].");
        $this->assertSame('persistent', $notification['duration']);
        $this->assertSame('danger', $notification['status']);

        return $failure;
    }

    public function test_the_page_offers_registering_when_no_app_is_configured(): void
    {
        Livewire::test(ManageGitHubApp::class)
            ->assertSee('No GitHub App yet')
            ->assertActionExists('register');
    }

    public function test_the_page_is_read_only_when_the_environment_names_an_app(): void
    {
        config()->set('services.github.app.id', '777');
        config()->set('services.github.app.private_key', GitHubAppRegistrationFactory::privateKey());

        Livewire::test(ManageGitHubApp::class)
            ->assertSee('Configured from the environment')
            ->assertSee('777')
            ->assertActionHidden('register')
            ->assertActionHidden('forget');
    }

    public function test_the_sources_list_has_one_connect_menu_that_works_before_there_is_an_app(): void
    {
        Livewire::test(ListSources::class)
            ->assertSee('Connect GitHub')
            ->assertActionHasLabel('connectGithub', 'Connect an account')
            ->assertActionVisible('githubApp')
            ->assertActionHasLabel('githubApp', 'Register GitHub App')
            ->assertSee('Start here')
            ->assertActionEnabled('connectGithub');
    }

    public function test_connecting_with_no_app_registers_one_and_carries_on_to_installing(): void
    {
        Livewire::test(ListSources::class)
            ->callAction('connectGithub', data: [
                'owner_type' => GitHubAppOwner::User->value,
                'name' => 'Acme Packages',
            ])
            ->assertHasNoActionErrors()
            ->assertRedirect(route('github-app.manifest'));

        $this->assertSame(GitHubAppOwner::User->value, session(GitHubAppRegistrationController::SESSION_KEY)['owner_type']);
    }

    public function test_the_empty_sources_list_is_the_onboarding(): void
    {
        Livewire::test(ListSources::class)
            ->assertSee('Connect your first GitHub account')
            ->assertSee('A source is a GitHub account packages are pulled from.')
            ->assertSee('also registers this registry&#039;s GitHub App', false);

        GitHubAppRegistration::factory()->create();

        Livewire::test(ListSources::class)
            ->assertSee('Connect your first GitHub account')
            ->assertDontSee('also registers this registry');
    }

    public function test_once_registered_the_menu_connects_and_the_app_is_one_item_below(): void
    {
        GitHubAppRegistration::factory()->create();

        Livewire::test(ListSources::class)
            ->assertActionEnabled('connectGithub')
            ->assertActionHasUrl('connectGithub', route('sources.connect.new'))
            ->assertActionHasLabel('githubApp', 'GitHub App')
            ->assertDontSee('Start here');
    }

    public function test_the_registration_modal_says_how_registering_differs_from_connecting(): void
    {
        Livewire::test(ManageGitHubApp::class)
            ->mountAction('register')
            ->assertMountedActionModalSee('You create it once, for this registry')
            ->assertMountedActionModalSee('the account chooses which repositories it may read');
    }

    public function test_registering_for_an_organisation_starts_a_manifest_flow(): void
    {
        Livewire::test(ManageGitHubApp::class)
            ->callAction('register', data: [
                'owner_type' => GitHubAppOwner::Organization->value,
                'owner' => 'acme',
                'name' => 'Acme Packages',
            ])
            ->assertHasNoActionErrors()
            ->assertRedirect(route('github-app.manifest'));

        $pending = session(GitHubAppRegistrationController::SESSION_KEY);

        $this->assertSame('acme', $pending['owner']);
        $this->assertSame(40, strlen($pending['state']));
    }

    /** @return array<string, array{GitHubAppOwner, ?string, bool}> */
    public static function ownersAndVisibility(): array
    {
        return [
            'personal, only this account' => [GitHubAppOwner::User, null, false],
            'personal, any account' => [GitHubAppOwner::User, null, true],
            'organisation, only this account' => [GitHubAppOwner::Organization, 'acme', false],
            'organisation, any account' => [GitHubAppOwner::Organization, 'acme', true],
        ];
    }

    #[DataProvider('ownersAndVisibility')]
    public function test_who_can_install_the_app_reaches_githubs_manifest(GitHubAppOwner $owner, ?string $login, bool $public): void
    {
        Livewire::test(ManageGitHubApp::class)
            ->callAction('register', data: [
                'owner_type' => $owner->value,
                'owner' => $login,
                'public' => (int) $public,
                'name' => 'Acme Packages',
            ])
            ->assertHasNoActionErrors()
            ->assertRedirect(route('github-app.manifest'));

        $this->assertSame($public, session(GitHubAppRegistrationController::SESSION_KEY)['public']);

        $response = $this->get(route('github-app.manifest'))->assertOk();
        $response->assertSee('action="'.$owner->newAppUrl($login).'?state=', false);
        preg_match('/name="manifest" value="([^"]+)"/', $response->getContent(), $matches);

        $this->assertSame($public, json_decode(html_entity_decode($matches[1]), true)['public']);
    }

    public function test_only_this_account_is_the_default_with_its_reason(): void
    {
        Livewire::test(ManageGitHubApp::class)
            ->mountAction('register')
            ->assertSchemaStateSet(['public' => 0])
            ->assertMountedActionModalSee('Only this account')
            ->assertMountedActionModalSee('Any account')
            ->assertMountedActionModalSee('is the default because it is the narrowest')
            ->assertMountedActionModalSee('Neither choice makes your repositories or this registry public');
    }

    public function test_an_enterprise_app_can_only_be_installed_within_the_enterprise(): void
    {
        Livewire::test(ManageGitHubApp::class)
            ->mountAction('register')
            ->fillForm(['public' => 1])
            ->fillForm(['owner_type' => GitHubAppOwner::Enterprise->value])
            // Picking an enterprise drops an earlier "Any account".
            ->assertSchemaStateSet(['public' => 0])
            ->assertMountedActionModalSee('Only this enterprise&#039;s organisations', false)
            ->assertMountedActionModalDontSee('Any account');

        Livewire::test(ManageGitHubApp::class)
            ->callAction('register', data: [
                'owner_type' => GitHubAppOwner::Enterprise->value,
                'owner' => 'acme',
                'public' => 1,
                'name' => 'Acme Packages',
            ])
            ->assertHasActionErrors(['public']);

        $this->assertNull(session('github-app.enterprise'));

        $this->expectException(InvalidArgumentException::class);
        (new GitHubAppManifest('Acme Packages', public: true))->prefillUrl(GitHubAppOwner::Enterprise, 'acme');
    }

    public function test_the_wizard_connect_step_asks_who_can_install_the_app(): void
    {
        Livewire::test(GetStarted::class)
            ->mountAction(TestAction::make('connectGithub')->schemaComponent('onboarding.connect.actions'))
            ->assertMountedActionModalSee('Who owns the App on GitHub?')
            ->assertMountedActionModalSee('Who can install it?');
    }

    public function test_the_manifest_is_posted_to_the_owners_new_app_page_with_the_state(): void
    {
        $this->pending();

        $response = $this->get(route('github-app.manifest'))->assertOk();

        $response->assertSee('action="https://github.com/organizations/acme/settings/apps/new?state=the-state"', false);

        preg_match('/name="manifest" value="([^"]+)"/', $response->getContent(), $matches);
        $manifest = json_decode(html_entity_decode($matches[1]), true);

        $this->assertSame('Acme Packages', $manifest['name']);
        $this->assertSame(['url' => route('webhooks.github'), 'active' => true], $manifest['hook_attributes']);
        $this->assertSame(route('github-app.registered'), $manifest['redirect_url']);
        $this->assertSame(route('sources.github.callback'), $manifest['setup_url']);
        $this->assertTrue($manifest['setup_on_update']);
        $this->assertFalse($manifest['public']);
        $this->assertSame(['contents' => 'read', 'metadata' => 'read'], $manifest['default_permissions']);
        $this->assertSame(['push', 'create', 'delete'], $manifest['default_events']);
    }

    public function test_a_personal_app_is_registered_on_the_users_own_settings(): void
    {
        $this->pending(GitHubAppOwner::User, null);

        $this->get(route('github-app.manifest'))
            ->assertSee('action="https://github.com/settings/apps/new?state=the-state"', false);
    }

    public function test_the_callback_refuses_a_state_this_browser_did_not_start(): void
    {
        $this->pending();

        $this->get(route('github-app.registered', ['code' => 'the-code', 'state' => 'forged']))
            ->assertRedirect(route('filament.admin.resources.github-app.index'));

        Http::assertNothingSent();
        $this->assertDatabaseCount('github_app_registrations', 0);
        $this->assertNull(session(GitHubAppRegistrationController::SESSION_KEY));

        $this->assertFailedPersistently('Registration could not be verified');

        Livewire::test(ManageGitHubApp::class)
            ->assertSee('Registration could not be verified')
            ->assertSee('delete it on GitHub');
    }

    public function test_a_refused_conversion_shows_githubs_whole_answer_until_dismissed(): void
    {
        $this->pending();

        // Long enough that RequestException's own message would cut it short.
        $message = 'Invalid request. '.str_repeat('For \'links/0/schema\', [] is not a null or object. ', 6);

        $this->fakeConversion(refusal: [
            'message' => $message,
            'errors' => ['The manifest could not be read.', ['resource' => 'Integration', 'field' => 'name', 'code' => 'already_exists']],
            'documentation_url' => 'https://docs.github.com/rest/apps/apps#create-a-github-app-from-a-manifest',
        ]);

        $this->get(route('github-app.registered', ['code' => 'the-code', 'state' => 'the-state']))
            ->assertRedirect(route('filament.admin.resources.github-app.index'));

        $failure = $this->assertFailedPersistently('GitHub created the app, but its credentials could not be collected');

        $this->assertStringContainsString("GitHub answered 422: {$message}", $failure['detail']);
        $this->assertStringContainsString('The manifest could not be read. Integration name already_exists', $failure['detail']);
        $this->assertStringNotContainsString('truncated', $failure['detail']);
        $this->assertStringNotContainsString('the-code', json_encode($failure));
        $this->assertStringContainsString('https://github.com/organizations/acme/settings/apps', $failure['next']);
        $this->assertDatabaseCount('github_app_registrations', 0);

        // Still there on the next visit, however long that takes.
        Livewire::test(ManageGitHubApp::class)
            ->assertSee('its credentials could not be collected')
            ->assertSee(trim($message))
            ->assertSee('register again with a new name')
            ->callAction(TestAction::make('dismissFailure')->schemaComponent('failure'))
            ->assertRedirect(ManageGitHubApp::getUrl());

        $this->assertNull(session('github-app.failure'));

        Livewire::test(ManageGitHubApp::class)->assertDontSee('its credentials could not be collected');
    }

    public function test_the_next_attempt_clears_the_last_failure(): void
    {
        ManageGitHubApp::fail('Registration could not be verified', 'Detail.', 'Next.');

        Livewire::test(ManageGitHubApp::class)
            ->callAction('register', data: [
                'owner_type' => GitHubAppOwner::User->value,
                'name' => 'Acme Packages',
            ]);

        $this->assertNull(session('github-app.failure'));
    }

    public function test_the_callback_stores_the_converted_credentials_and_moves_on_to_installing(): void
    {
        $this->pending();
        $pem = $this->fakeConversion();

        $this->get(route('github-app.registered', ['code' => 'the-code', 'state' => 'the-state']))
            ->assertRedirect(route('sources.connect.new'));

        $registration = GitHubAppRegistration::query()->sole();

        $this->assertSame('123456', $registration->app_id);
        $this->assertSame('acme-packages', $registration->slug);
        $this->assertSame('acme', $registration->owner);
        $this->assertSame(GitHubAppOwner::Organization, $registration->owner_type);
        $this->assertSame($pem, $registration->private_key);
        $this->assertSame('the-generated-secret', $registration->webhook_secret);

        $row = (array) $registration->getConnection()->table('github_app_registrations')->sole();
        $this->assertStringNotContainsString('PRIVATE KEY', $row['private_key']);
        $this->assertStringNotContainsString('never-stored', json_encode($row));

        $this->assertTrue(app(GitHubApp::class)->isConfigured());
        $this->assertNull(session(GitHubAppRegistrationController::SESSION_KEY));

        // GitHub refuses the conversion with a 422 when it carries a body,
        // even an empty JSON array, which the fake above would not notice.
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.github.com/app-manifests/the-code/conversions'
            && $request->body() === '');
    }

    public function test_a_registration_started_in_the_wizard_hands_it_the_install_that_follows(): void
    {
        $this->pending();
        $this->fakeConversion();
        session([GetStarted::RETURN_KEY => true]);

        $this->get(route('github-app.registered', ['code' => 'the-code', 'state' => 'the-state']))
            ->assertRedirect(route('sources.connect.new', ['onboarding' => 1]));

        $this->assertNull(session(GetStarted::RETURN_KEY));
    }

    public function test_a_failed_registration_started_in_the_wizard_returns_to_it(): void
    {
        $this->pending();
        session([GetStarted::RETURN_KEY => true]);

        $this->get(route('github-app.registered', ['code' => 'the-code', 'state' => 'forged']))
            ->assertRedirect(GetStarted::getUrl());

        // Said where the admin is looking, and kept for the app's own page.
        $this->assertStringContainsString('Registration could not be verified', (string) json_encode(session('filament.notifications')));
        $this->assertFailedPersistently('Registration could not be verified');
        $this->assertNull(session(GetStarted::RETURN_KEY));
    }

    public function test_the_install_that_follows_reports_the_registration_once_it_is_done(): void
    {
        $this->pending();
        $this->fakeConversion();

        $this->get(route('github-app.registered', ['code' => 'the-code', 'state' => 'the-state']))
            ->assertRedirect(route('sources.connect.new'));

        // Nothing yet: it would only be shown after GitHub's install screen,
        // by when "now choose the account" is out of date.
        $this->assertEmpty(session('filament.notifications'));

        $this->get(route('sources.connect.new'))
            ->assertRedirectContains('https://github.com/apps/acme-packages/installations/new');

        $this->assertNull(session(GitHubAppRegistrationController::REGISTERED_KEY));
        $state = session('sources.github.pending')['state'];

        Http::fake([
            'api.github.com/app/installations/42/access_tokens' => Http::response([
                'token' => 'ghs_installation',
                'expires_at' => now()->addHour()->toIso8601String(),
            ]),
            'api.github.com/app/installations/42' => Http::response([
                'account' => ['login' => 'acme', 'type' => 'Organization'],
                'app_slug' => 'acme-packages',
                'repository_selection' => 'all',
            ]),
            'api.github.com/installation/repositories*' => Http::response(['total_count' => 21]),
            'api.github.com/app/hook/config' => Http::response(['url' => route('webhooks.github')]),
        ]);

        $this->get(route('sources.github.callback', ['installation_id' => 42, 'state' => $state]))->assertRedirect();

        $notifications = $this->notifications();

        $this->assertCount(1, $notifications);
        $this->assertSame('Connected acme', $notifications->first()['title']);

        // And the next step, which is importing that account's packages.
        $source = Source::query()->where('account', 'acme')->sole();
        $this->assertSame('Import packages', $notifications->first()['actions'][0]['label']);
        $this->assertSame('persistent', $notifications->first()['duration']);
        $this->assertSame(
            PackageResource::getUrl('index', ['action' => 'importFromSource', 'actionArguments' => ['source' => $source->id]]),
            $notifications->first()['actions'][0]['url'],
        );
        $this->assertStringStartsWith('Registered the GitHub App Acme Packages. 21 repositories are reachable', $notifications->first()['body']);
    }

    public function test_the_callback_stores_nothing_when_github_rejects_the_key(): void
    {
        $this->pending();
        $this->fakeConversion(401);

        $this->get(route('github-app.registered', ['code' => 'the-code', 'state' => 'the-state']))
            ->assertRedirect(route('filament.admin.resources.github-app.index'));

        $this->assertDatabaseCount('github_app_registrations', 0);

        $failure = $this->assertFailedPersistently('GitHub created the app, but it could not be verified');

        $this->assertStringContainsString('GitHub answered 401: A JSON web token could not be decoded', $failure['detail']);
        $this->assertStringContainsString('Delete the app on GitHub', $failure['next']);
    }

    public function test_an_enterprise_app_is_registered_from_a_prefilled_form_and_an_uploaded_key(): void
    {
        Livewire::test(ManageGitHubApp::class)
            ->callAction('register', data: [
                'owner_type' => GitHubAppOwner::Enterprise->value,
                'owner' => 'acme',
                'public' => 0,
                'name' => 'Acme Packages',
            ])
            ->assertHasNoActionErrors()
            ->assertRedirect(ManageGitHubApp::getUrl());

        $this->assertFalse(session('github-app.enterprise')['public']);

        $page = Livewire::test(ManageGitHubApp::class)
            ->assertSee('1. Create the app on GitHub')
            ->assertSee('Private keys')
            ->assertSee('3. Enter the App ID and upload the key')
            // The registry sets the secret itself, so there is none to copy.
            ->assertDontSee('Copy the webhook secret')
            ->assertActionVisible('complete')
            ->assertActionHidden('register')
            // GitHub's form, prefilled, opens from step 1.
            ->assertSeeHtml('enterprises\\/acme\\/settings\\/apps\\/new?')
            // Installable within the enterprise only: GitHub's private visibility.
            ->assertSeeHtml('public=false');

        // Steps done on GitHub's side tick off as their buttons are used.
        $page->call('markEnterpriseStep', 'opened')
            ->call('markEnterpriseStep', 'key')
            ->call('markEnterpriseStep', 'not-a-step');

        $this->assertSame(['opened', 'key'], session('github-app.enterprise')['done']);

        $pem = GitHubAppRegistrationFactory::privateKey();

        Http::fake([
            'api.github.com/app' => Http::response(['id' => 654321, 'slug' => 'acme-packages', 'name' => 'Acme Packages']),
            'api.github.com/app/hook/config' => Http::response(['url' => route('webhooks.github'), 'content_type' => 'json']),
        ]);

        $page->callAction('complete', data: [
            'app_id' => '654321',
            'private_key' => UploadedFile::fake()->createWithContent('acme-packages.pem', $pem),
        ])
            ->assertHasNoActionErrors()
            // On to installing it, as the manifest path does.
            ->assertRedirect(route('sources.connect.new'));

        $this->assertSame('Acme Packages', session(GitHubAppRegistrationController::REGISTERED_KEY));

        $registration = GitHubAppRegistration::query()->sole();

        $this->assertSame('654321', $registration->app_id);
        $this->assertSame(GitHubAppOwner::Enterprise, $registration->owner_type);
        $this->assertSame('acme', $registration->owner);
        $this->assertSame($pem, $registration->private_key);
        $this->assertNull(session('github-app.enterprise'));

        // The secret the registry chose is the one it gave the app's webhook,
        // signed for as the app itself.
        $this->assertSame(40, strlen((string) $registration->webhook_secret));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === 'https://api.github.com/app/hook/config'
            && $request->data() === [
                'url' => route('webhooks.github'),
                'content_type' => 'json',
                'secret' => $registration->webhook_secret,
                'insecure_ssl' => '0',
            ]
            && $this->issuer($request) === '654321');
    }

    public function test_an_enterprise_app_whose_webhook_refuses_its_secret_is_kept_and_can_try_again(): void
    {
        session()->put('github-app.enterprise', ['owner' => 'acme', 'name' => 'Acme Packages', 'done' => []]);

        Http::fake([
            'api.github.com/app' => Http::response(['id' => 654321, 'slug' => 'acme-packages', 'name' => 'Acme Packages']),
            'api.github.com/app/hook/config' => Http::sequence()
                ->push(['message' => 'Not Found'], 404)
                ->push(['url' => route('webhooks.github')]),
        ]);

        Livewire::test(ManageGitHubApp::class)
            ->callAction('complete', data: [
                'app_id' => '654321',
                'private_key' => UploadedFile::fake()->createWithContent('key.pem', GitHubAppRegistrationFactory::privateKey()),
            ])
            ->assertRedirect(ManageGitHubApp::getUrl());

        $registration = GitHubAppRegistration::query()->sole();
        $this->assertNull($registration->webhook_secret);

        $failure = $this->assertFailedPersistently('The app is registered, but its webhook has no secret yet');
        $this->assertStringContainsString('tick Webhook → Active', $failure['detail']);
        $this->assertStringContainsString('GitHub answered 404: Not Found', $failure['detail']);

        Livewire::test(ManageGitHubApp::class)
            ->assertSee('its webhook has no secret yet')
            ->assertActionVisible('configureWebhook')
            ->callAction('configureWebhook')
            ->assertRedirect(ManageGitHubApp::getUrl());

        $this->assertSame(40, strlen((string) $registration->refresh()->webhook_secret));
        $this->assertNull(session('github-app.failure'));

        Livewire::test(ManageGitHubApp::class)->assertActionHidden('configureWebhook');
    }

    public function test_the_default_app_name_carries_the_site_so_it_is_free_on_github(): void
    {
        config(['app.name' => 'Package Pipeline']);

        $names = collect([
            'https://packages.acme.test' => 'Package Pipeline (acme)',
            'http://localhost:8000' => 'Package Pipeline (localhost)',
            'https://pp.test' => 'Package Pipeline (pp.test)',
            'http://package-pipeline-github-connect.test' => 'package-pipeline-github-connect',
        ])->map(function (string $expected, string $url): string {
            config(['app.url' => $url]);

            return RegisterGitHubAppAction::defaultName();
        });

        $this->assertSame([
            'https://packages.acme.test' => 'Package Pipeline (acme)',
            'http://localhost:8000' => 'Package Pipeline (localhost)',
            'https://pp.test' => 'Package Pipeline (pp.test)',
            'http://package-pipeline-github-connect.test' => 'package-pipeline-github-connect',
        ], $names->all());
    }

    public function test_the_default_app_name_on_shared_platforms_uses_the_instance(): void
    {
        config(['app.name' => 'Package Pipeline']);

        foreach (['laravel.cloud', 'herokuapp.com', 'vercel.app', 'fly.dev', 'onrender.com'] as $platform) {
            config(['app.url' => "https://acme-registry.{$platform}"]);
            $this->assertSame('Package Pipeline (acme-registry)', RegisterGitHubAppAction::defaultName());
        }
    }

    public function test_the_organisation_default_uses_the_app_name_and_owner(): void
    {
        config(['app.name' => 'Acme Packages']);

        Livewire::test(ManageGitHubApp::class)
            ->mountAction('register')
            ->assertMountedActionModalSee('If GitHub says the name is taken, you can change it on the next page; the registry uses whatever name you create there.')
            ->fillForm(['owner' => 'acme'])
            ->assertSchemaStateSet(['name' => 'Acme Packages: acme']);
    }

    public function test_the_enterprise_default_uses_the_app_name_and_owner(): void
    {
        config(['app.name' => 'Acme Packages']);

        Livewire::test(ManageGitHubApp::class)
            ->mountAction('register')
            ->fillForm(['owner_type' => GitHubAppOwner::Enterprise->value, 'owner' => 'acme-enterprise'])
            ->assertSchemaStateSet(['name' => 'Acme Packages: acme-enterprise']);
    }

    public function test_the_default_tracks_owner_and_type_until_the_name_is_edited(): void
    {
        config(['app.name' => 'Acme Packages', 'app.url' => 'https://acme-registry.laravel.cloud']);

        Livewire::test(ManageGitHubApp::class)
            ->mountAction('register')
            ->fillForm(['owner' => 'acme'])
            ->assertSchemaStateSet(['name' => 'Acme Packages: acme'])
            ->fillForm(['owner' => 'acme-team'])
            ->assertSchemaStateSet(['name' => 'Acme Packages: acme-team'])
            ->fillForm(['owner_type' => GitHubAppOwner::User->value])
            ->assertSchemaStateSet(['name' => 'Acme Packages (acme-registry)'])
            ->fillForm(['owner_type' => GitHubAppOwner::Enterprise->value])
            ->assertSchemaStateSet(['name' => 'Acme Packages: acme-team'])
            ->fillForm(['name' => 'My custom app'])
            ->fillForm(['owner' => 'another-team', 'owner_type' => GitHubAppOwner::Organization->value])
            ->assertSchemaStateSet(['name' => 'My custom app'])
            ->fillForm(['owner_type' => GitHubAppOwner::User->value])
            ->assertSchemaStateSet(['name' => 'My custom app']);
    }

    public function test_the_owner_is_preserved_when_the_app_name_needs_shortening(): void
    {
        config(['app.name' => 'Acme Labs Packages (preview)']);

        $this->assertSame('Acme Labs Packages: acme-team', RegisterGitHubAppAction::defaultName('acme-team'));
        $this->assertSame('Acme Labs: abcdefghijklmnopqrst', RegisterGitHubAppAction::defaultName('abcdefghijklmnopqrst'));
        $this->assertSame(str_repeat('a', 34), RegisterGitHubAppAction::defaultName(str_repeat('a', 40)));
        $this->assertSame(str_repeat('a', 33), RegisterGitHubAppAction::defaultName(str_repeat('a', 33)));
        $this->assertSame('A: '.str_repeat('a', 31), RegisterGitHubAppAction::defaultName(str_repeat('a', 31)));
    }

    public function test_the_enterprise_prefill_describes_the_same_app_as_the_manifest(): void
    {
        $url = (new GitHubAppManifest('Acme Packages'))->prefillUrl(GitHubAppOwner::Enterprise, 'acme');

        $this->assertStringStartsWith('https://github.com/enterprises/acme/settings/apps/new?', $url);
        $this->assertStringContainsString('webhook_url='.urlencode(route('webhooks.github')), $url);
        $this->assertStringContainsString('setup_url='.urlencode(route('sources.github.callback')), $url);
        $this->assertStringContainsString('contents=read', $url);
        $this->assertStringContainsString('events%5B%5D=push&events%5B%5D=create&events%5B%5D=delete', $url);
    }

    public function test_an_enterprise_key_from_another_app_is_rejected(): void
    {
        session()->put('github-app.enterprise', ['owner' => 'acme', 'name' => 'Acme Packages', 'done' => []]);

        Http::fake(['api.github.com/app' => Http::response(['id' => 999, 'slug' => 'someone-else'])]);

        Livewire::test(ManageGitHubApp::class)
            ->callAction('complete', data: [
                'app_id' => '654321',
                'private_key' => UploadedFile::fake()->createWithContent('key.pem', GitHubAppRegistrationFactory::privateKey()),
            ])
            ->assertRedirect(ManageGitHubApp::getUrl());

        $this->assertDatabaseCount('github_app_registrations', 0);
        $this->assertNotNull(session('github-app.enterprise'));

        $failure = $this->assertFailedPersistently('The App ID and key were not saved');

        $this->assertStringContainsString('does not belong to app 654321', $failure['detail']);

        // Back on the page with the form to try again, and the reason above it.
        Livewire::test(ManageGitHubApp::class)
            ->assertSee('The App ID and key were not saved')
            ->assertSee('does not belong to app 654321')
            ->assertActionVisible('complete');
    }

    public function test_forgetting_says_how_many_sources_depend_on_the_app_and_keeps_them(): void
    {
        GitHubAppRegistration::factory()->create();
        Source::factory()->count(2)->create(['installation_id' => fn () => fake()->unique()->numberBetween(1, 99999)]);
        Source::factory()->disconnected()->create();

        Livewire::test(ManageGitHubApp::class)
            ->mountAction('forget')
            ->assertMountedActionModalSee('2 sources are connected through it')
            ->callMountedAction();

        $this->assertDatabaseCount('github_app_registrations', 0);
        $this->assertSame(3, Source::query()->count());
        $this->assertFalse(app(GitHubApp::class)->isConfigured());
    }

    public function test_registering_needs_the_permission_to(): void
    {
        $this->actingAs(User::factory()->create());

        $this->pending();

        $this->get(route('github-app.manifest'))->assertForbidden();
        $this->get(route('github-app.registered', ['code' => 'the-code', 'state' => 'the-state']))->assertForbidden();
        $this->get(route('filament.admin.resources.github-app.index'))->assertForbidden();

        Http::assertNotSent(fn (Request $request): bool => true);
    }

    public function test_the_table_allows_only_one_registration_even_mid_race(): void
    {
        GitHubAppRegistration::factory()->create();

        // The code checks before it creates, but a race passes that check
        // twice; the row is what stops the second one.
        $this->expectException(UniqueConstraintViolationException::class);

        GitHubAppRegistration::factory()->create();
    }
}
