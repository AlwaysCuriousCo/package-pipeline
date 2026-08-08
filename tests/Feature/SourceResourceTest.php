<?php

namespace Tests\Feature;

use App\Enums\SourceProvider;
use App\Enums\WebhookState;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\Packages\Pages\EditPackage;
use App\Filament\Resources\Sources\Pages\CreateSource;
use App\Filament\Resources\Sources\Pages\EditSource;
use App\Filament\Resources\Sources\Pages\ListSources;
use App\Filament\Resources\Sources\Pages\ViewSource;
use App\Filament\Resources\Sources\RelationManagers\PackagesRelationManager;
use App\Models\Package;
use App\Models\Source;
use App\Models\User;
use App\Services\GitHub\GitHubApp;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SourceResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->superAdmin()->create());
    }

    private function configureGitHubApp(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        config()->set('services.github.app', [
            'id' => '123456',
            'slug' => 'acme-pipeline',
            'private_key' => $pem,
            'api_url' => 'https://api.github.com',
        ]);
    }

    /**
     * An app that can be asked about its webhook, and a secret to verify the
     * deliveries it would send.
     */
    private function configureGitHubAppWebhook(): void
    {
        $this->configureGitHubApp();

        config()->set('services.github.app.webhook_secret', 'the-app-webhook-secret');
    }

    public function test_the_index_offers_connecting_without_creating_a_record_first(): void
    {
        $this->configureGitHubApp();

        Livewire::test(ListSources::class)
            ->assertActionExists('connectGithub')
            ->assertActionEnabled('connectGithub')
            ->assertSee(route('sources.connect.new'));
    }

    public function test_connecting_is_disabled_when_no_github_app_is_registered(): void
    {
        config()->set('services.github.app', ['id' => null, 'slug' => null, 'private_key' => null]);

        Livewire::test(ListSources::class)
            ->assertActionExists('connectGithub')
            ->assertActionDisabled('connectGithub');
    }

    public function test_the_source_index_lists_records(): void
    {
        $sources = Source::factory()->count(3)->create();

        Livewire::test(ListSources::class)
            ->assertCanSeeTableRecords($sources);
    }

    public function test_a_source_can_be_created(): void
    {
        Livewire::test(CreateSource::class)
            ->fillForm([
                'name' => 'Acme engineering',
                'provider' => SourceProvider::Github->value,
                'account' => 'acme',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('sources', [
            'name' => 'Acme engineering',
            'provider' => 'github',
            'account' => 'acme',
            'installation_id' => null,
            'connected_at' => null,
        ]);
    }

    public function test_the_source_name_and_account_must_be_unique(): void
    {
        Source::factory()->create(['name' => 'Acme', 'account' => 'acme']);

        Livewire::test(CreateSource::class)
            ->fillForm([
                'name' => 'Acme',
                'provider' => SourceProvider::Github->value,
                'account' => 'acme',
            ])
            ->call('create')
            ->assertHasFormErrors(['name', 'account']);
    }

    public function test_an_account_already_held_by_another_source_is_refused(): void
    {
        Source::factory()->create(['name' => 'Acme', 'account' => 'acme']);

        Livewire::test(CreateSource::class)
            ->fillForm([
                'name' => 'Acme spare',
                'provider' => SourceProvider::Github->value,
                // GitHub logins are case insensitive, so this is the same owner.
                'account' => 'ACME',
            ])
            ->call('create')
            ->assertHasFormErrors(['account']);

        $this->assertDatabaseCount('sources', 1);
    }

    public function test_a_source_can_be_saved_without_colliding_with_its_own_account(): void
    {
        $source = Source::factory()->create(['name' => 'Acme', 'account' => 'acme']);

        Livewire::test(EditSource::class, ['record' => $source->getKey()])
            ->fillForm(['name' => 'Acme engineering'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Acme engineering', $source->fresh()->name);
    }

    public function test_editing_a_source_onto_another_sources_account_is_refused(): void
    {
        Source::factory()->create(['name' => 'Acme', 'account' => 'acme']);
        $other = Source::factory()->create(['name' => 'Spare', 'account' => 'spare']);

        Livewire::test(EditSource::class, ['record' => $other->getKey()])
            ->fillForm(['account' => 'acme'])
            ->call('save')
            ->assertHasFormErrors(['account']);

        $this->assertSame('spare', $other->fresh()->account);
    }

    public function test_the_stored_token_is_never_sent_to_the_browser(): void
    {
        $source = Source::factory()->withToken('github_pat_secret')->create();

        Livewire::test(EditSource::class, ['record' => $source->getKey()])
            ->assertFormSet(['token' => null])
            ->assertDontSee('github_pat_secret');
    }

    public function test_saving_without_a_new_token_keeps_the_existing_one(): void
    {
        $source = Source::factory()->withToken('github_pat_secret')->create();

        Livewire::test(EditSource::class, ['record' => $source->getKey()])
            ->fillForm(['name' => 'Renamed'])
            ->call('save')
            ->assertHasNoFormErrors();

        $source->refresh();

        $this->assertSame('Renamed', $source->name);
        $this->assertSame('github_pat_secret', $source->token);
    }

    public function test_the_view_page_shows_the_source(): void
    {
        $source = Source::factory()->create(['account' => 'acme']);

        Livewire::test(ViewSource::class, ['record' => $source->getKey()])
            ->assertOk()
            ->assertSee($source->account)
            ->assertSee('GitHub App installation #'.$source->installation_id);
    }

    /**
     * The app's webhook is set up once and covers every repository under every
     * source, so the source page is where an admin is told how — with the
     * secret generated for them rather than asked for.
     */
    public function test_the_view_page_offers_the_webhook_settings_when_none_is_configured(): void
    {
        config(['services.github.app.webhook_secret' => null]);

        $source = Source::factory()->create(['account' => 'acme']);

        Livewire::test(ViewSource::class, ['record' => $source->getKey()])
            ->assertOk()
            ->assertSee('No secret set')
            ->assertSee(route('webhooks.github'))
            ->assertSee('GITHUB_APP_WEBHOOK_SECRET')
            ->assertSee(app(GitHubApp::class)->suggestedWebhookSecret());
    }

    /**
     * A secret in the environment says nothing about whether the app was ever
     * given a webhook to sign with it, so GitHub is asked.
     */
    public function test_an_app_with_no_webhook_of_its_own_is_reported_as_not_switched_on(): void
    {
        $this->configureGitHubAppWebhook();

        Http::fake(['api.github.com/app/hook/config' => Http::response(['message' => 'Not Found'], 404)]);

        $this->assertSame(WebhookState::Absent, app(GitHubApp::class)->webhookState());
        $this->assertFalse(app(GitHubApp::class)->hasWebhook());

        Livewire::test(ViewSource::class, ['record' => Source::factory()->create()->getKey()])
            ->assertOk()
            ->assertSee('Not switched on');
    }

    public function test_an_app_webhook_pointing_at_another_environment_is_not_counted_as_coverage(): void
    {
        $this->configureGitHubAppWebhook();

        Http::fake(['api.github.com/app/hook/config' => Http::response([
            'url' => 'https://packages.example.com/incoming/github',
            'content_type' => 'json',
        ])]);

        $this->assertSame(WebhookState::Elsewhere, app(GitHubApp::class)->webhookState());
        $this->assertFalse(app(GitHubApp::class)->hasWebhook());
        $this->assertSame('https://packages.example.com/incoming/github', app(GitHubApp::class)->deliveringTo());
    }

    public function test_an_app_webhook_posting_here_is_confirmed(): void
    {
        $this->configureGitHubAppWebhook();

        Http::fake(['api.github.com/app/hook/config' => Http::response([
            'url' => route('webhooks.github'),
            'content_type' => 'json',
        ])]);

        $this->assertSame(WebhookState::Delivering, app(GitHubApp::class)->webhookState());
        $this->assertTrue(app(GitHubApp::class)->hasWebhook());
    }

    /**
     * GitHub being unreachable is not evidence that the webhook is gone, and
     * treating it as such would grow a repository hook on every package
     * created during an outage.
     */
    public function test_github_being_unreachable_leaves_the_configured_secret_its_benefit_of_the_doubt(): void
    {
        $this->configureGitHubAppWebhook();

        Http::fake(['api.github.com/app/hook/config' => Http::response(['message' => 'Bad gateway'], 502)]);

        $this->assertSame(WebhookState::Unverifiable, app(GitHubApp::class)->webhookState());
        $this->assertTrue(app(GitHubApp::class)->hasWebhook());
    }

    public function test_rechecking_asks_github_again_rather_than_the_cache(): void
    {
        $this->configureGitHubAppWebhook();

        // The webhook is switched on between the two checks, which the cached
        // answer would go on denying for another five minutes.
        Http::fakeSequence('api.github.com/app/hook/config')
            ->push(['message' => 'Not Found'], 404)
            ->push(['url' => route('webhooks.github')]);

        $this->assertFalse(app(GitHubApp::class)->hasWebhook());

        Livewire::test(ViewSource::class, ['record' => Source::factory()->create()->getKey()])
            ->callAction(TestAction::make('recheckWebhook')->schemaComponent('webhook'))
            ->assertNotified();

        $this->assertTrue(app(GitHubApp::class)->hasWebhook());
    }

    public function test_the_view_page_stops_offering_a_secret_once_one_is_set(): void
    {
        config(['services.github.app.webhook_secret' => 'already-set']);

        $source = Source::factory()->create(['account' => 'acme']);

        Livewire::test(ViewSource::class, ['record' => $source->getKey()])
            ->assertOk()
            ->assertSee('Configured')
            // The configured secret lives in the environment and is never read
            // back out to a browser.
            ->assertDontSee('already-set')
            ->assertDontSee(app(GitHubApp::class)->suggestedWebhookSecret());
    }

    /**
     * A token-based source does not authenticate as the app, so the app's
     * webhook has nothing to do with its repositories.
     */
    public function test_a_token_source_is_not_offered_the_app_webhook_settings(): void
    {
        config(['services.github.app.webhook_secret' => null]);

        $source = Source::factory()->withToken()->create(['account' => 'acme']);

        Livewire::test(ViewSource::class, ['record' => $source->getKey()])
            ->assertOk()
            ->assertDontSee('GITHUB_APP_WEBHOOK_SECRET');
    }

    public function test_the_view_page_lists_the_packages_bridged_through_the_source(): void
    {
        $source = Source::factory()->create(['account' => 'acme']);
        $mine = Package::factory()->create(['repository' => 'https://github.com/acme/widgets']);
        $theirs = Package::factory()->create(['repository' => 'https://github.com/other/widgets']);

        Livewire::test(PackagesRelationManager::class, [
            'ownerRecord' => $source,
            'pageClass' => ViewSource::class,
        ])
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_the_packages_list_links_to_creating_a_package_for_the_source(): void
    {
        $source = Source::factory()->create(['account' => 'acme']);

        Livewire::test(PackagesRelationManager::class, [
            'ownerRecord' => $source,
            'pageClass' => ViewSource::class,
        ])
            ->assertActionHasUrl(
                TestAction::make('createPackage')->table(),
                PackageResource::getUrl('create', ['source' => $source->getKey()]),
            );
    }

    public function test_the_test_connection_action_records_the_result(): void
    {
        Http::fake([
            'api.github.com/orgs/acme/repos*' => Http::response([
                ['full_name' => 'acme/widgets'],
            ]),
        ]);

        $source = Source::factory()->withToken()->create(['account' => 'acme']);
        $source->forceFill(['connected_at' => null, 'connection_error' => 'stale failure'])->save();

        Livewire::test(ViewSource::class, ['record' => $source->getKey()])
            ->callAction('test');

        $source->refresh();

        $this->assertTrue($source->isConnected());
        $this->assertNull($source->connection_error);
        $this->assertSame(1, $source->metadata['repository_count']);
    }

    public function test_the_disconnect_action_clears_the_credentials(): void
    {
        $source = Source::factory()->withToken()->create();

        Livewire::test(ViewSource::class, ['record' => $source->getKey()])
            ->callAction('disconnect');

        $source->refresh();

        $this->assertNull($source->token);
        $this->assertFalse($source->isConnected());
    }

    public function test_the_package_form_lists_every_source_and_flags_the_unconnected_ones(): void
    {
        $connected = Source::factory()->create(['name' => 'Acme']);
        $pending = Source::factory()->disconnected()->create(['name' => 'Pending']);

        $options = Source::options();

        $this->assertSame('Acme (GitHub)', $options[$connected->id]);
        $this->assertSame('Pending (GitHub) — not connected', $options[$pending->id]);
    }

    public function test_editing_a_package_keeps_a_link_to_a_disconnected_source(): void
    {
        $source = Source::factory()->disconnected()->create(['account' => 'acme']);
        $package = Package::factory()->create([
            'source_id' => $source->id,
            'repository' => 'https://github.com/acme/widgets',
        ]);

        Livewire::test(EditPackage::class, ['record' => $package->getKey()])
            ->fillForm(['description' => 'Edited while the source is down.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame($source->id, $package->fresh()->source_id);
    }
}
