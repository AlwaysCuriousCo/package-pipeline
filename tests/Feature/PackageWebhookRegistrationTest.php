<?php

namespace Tests\Feature;

use App\Enums\WebhookCoverage;
use App\Filament\Resources\Packages\Pages\CreatePackage;
use App\Filament\Resources\Packages\Pages\EditPackage;
use App\Filament\Resources\Packages\Pages\ViewPackage;
use App\Models\Package;
use App\Models\Source;
use App\Models\User;
use App\Services\GitHub\WebhookRegistrar;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class PackageWebhookRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.github.app.webhook_secret' => 'the-app-webhook-secret']);

        // Without app credentials there is nothing to ask GitHub with, so the
        // configured secret is taken at its word. Stated rather than inherited
        // from whatever .env happens to hold, since it decides whether these
        // tests reach for the network at all.
        config(['services.github.app.id' => null, 'services.github.app.private_key' => null]);
    }

    /**
     * An app whose webhook GitHub confirms posts to this registry.
     */
    private function fakeDeliveringAppWebhook(): void
    {
        Http::fake(['api.github.com/app/hook/config' => Http::response(['url' => route('webhooks.github')])]);
    }

    /**
     * A repository that answers the composer.json read the wizard does, and
     * accepts a webhook.
     */
    private function fakeGitHub(): void
    {
        Http::fake([
            'api.github.com/repos/*/contents/composer.json*' => Http::response([
                'name' => 'acme/widgets',
                'description' => 'Widgets, assembled.',
            ]),
            'api.github.com/repos/*/hooks' => Http::response(['id' => 8675309], 201),
            'api.github.com/app/installations/*/access_tokens' => Http::response([
                'token' => 'ghs_installation',
                'expires_at' => '2099-01-01T00:00:00Z',
            ]),
        ]);
    }

    private function tokenSource(string $account = 'acme'): Source
    {
        return Source::factory()->withToken()->create(['account' => $account]);
    }

    /**
     * A source authenticating as an installed GitHub App, which has to be able
     * to mint a token before it can create anything on a repository.
     */
    private function installedSource(string $account = 'acme'): Source
    {
        openssl_pkey_export(openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]), $privateKey);

        config([
            'services.github.app.id' => '123456',
            'services.github.app.slug' => 'acme-pipeline',
            'services.github.app.private_key' => $privateKey,
        ]);

        return Source::factory()->create(['account' => $account]);
    }

    public function test_a_package_under_an_installed_app_needs_no_webhook_of_its_own(): void
    {
        $this->fakeDeliveringAppWebhook();

        $package = Package::factory()->create([
            'repository' => 'https://github.com/acme/widgets',
            'source_id' => Source::factory()->create(['account' => 'acme'])->id,
        ]);

        $this->assertSame(WebhookCoverage::Application, $package->webhookCoverage());
        $this->assertSame(WebhookCoverage::Application, app(WebhookRegistrar::class)->register($package));
        $this->assertNull($package->refresh()->webhook_id);

        // Creating a second hook on the repository would only sync it twice.
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/hooks'));
    }

    /**
     * The app's webhook is only coverage if GitHub says it delivers here. An
     * app registered with Webhook → Active unchecked delivers nothing, and a
     * package told it was covered would never get the repository hook that
     * would have worked — silently, which is the one thing auto-sync must not
     * do.
     */
    public function test_a_package_is_not_called_covered_by_an_app_webhook_that_was_never_switched_on(): void
    {
        $this->fakeGitHub();

        Http::fake(['api.github.com/app/hook/config' => Http::response(['message' => 'Not Found'], 404)]);

        $package = Package::factory()->create([
            'repository' => 'https://github.com/acme/widgets',
            'source_id' => $this->installedSource()->id,
        ]);

        $this->assertSame(WebhookCoverage::None, $package->webhookCoverage());
        $this->assertSame(WebhookCoverage::Repository, app(WebhookRegistrar::class)->register($package));
        $this->assertSame(8675309, $package->refresh()->webhook_id);
    }

    public function test_a_package_the_app_does_not_cover_gets_a_repository_webhook(): void
    {
        $this->fakeGitHub();

        $package = Package::factory()->create([
            'repository' => 'https://github.com/acme/widgets',
            'source_id' => $this->tokenSource()->id,
        ]);

        $this->assertSame(WebhookCoverage::Repository, app(WebhookRegistrar::class)->register($package));

        $package->refresh();

        $this->assertSame(8675309, $package->webhook_id);
        $this->assertNotNull($package->webhook_secret);
        $this->assertNull($package->webhook_error);

        Http::assertSent(function ($request) use ($package): bool {
            return $request->url() === 'https://api.github.com/repos/acme/widgets/hooks'
                && $request['config']['url'] === $package->webhookUrl()
                && $request['config']['content_type'] === 'json'
                && $request['config']['secret'] === $package->webhook_secret
                && $request['events'] === ['push', 'create', 'delete'];
        });
    }

    /**
     * The secret is the whole of the authentication on an incoming delivery,
     * so it must not be sitting in the database in the clear.
     */
    public function test_the_webhook_secret_is_encrypted_at_rest(): void
    {
        $this->fakeGitHub();

        $package = Package::factory()->create([
            'repository' => 'https://github.com/acme/widgets',
            'source_id' => $this->tokenSource()->id,
        ]);

        app(WebhookRegistrar::class)->register($package);

        $stored = (string) DB::table('packages')->where('id', $package->id)->value('webhook_secret');

        $this->assertNotSame($package->refresh()->webhook_secret, $stored);
        $this->assertStringNotContainsString($package->webhook_secret, $stored);
    }

    /**
     * Creating a hook needs admin rights on the repository, which a read-only
     * credential does not have. That is a fact about the token, not a reason
     * to fail whatever was being done at the time.
     */
    public function test_a_refused_webhook_is_recorded_rather_than_thrown(): void
    {
        Http::fake([
            'api.github.com/repos/*/hooks' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $package = Package::factory()->create([
            'repository' => 'https://github.com/acme/widgets',
            'source_id' => $this->tokenSource()->id,
        ]);

        $this->assertSame(WebhookCoverage::Failed, app(WebhookRegistrar::class)->register($package));

        $package->refresh();

        $this->assertNull($package->webhook_id);
        $this->assertNotNull($package->webhook_error);
        $this->assertNotNull(app(WebhookRegistrar::class)->unmetRequirement($package));
    }

    /**
     * The hook is created on GitHub before the id and secret are saved here.
     * If that save fails, the package still looks uncovered and a retry would
     * stack a second hook on the repository — so the one GitHub made has to
     * come back down.
     */
    public function test_a_hook_that_cannot_be_persisted_is_removed_from_github(): void
    {
        Http::fake([
            'api.github.com/repos/*/hooks' => Http::response(['id' => 8675309], 201),
            'api.github.com/repos/*/hooks/8675309' => Http::response(status: 204),
        ]);

        $package = Package::factory()->create([
            'repository' => 'https://github.com/acme/widgets',
            'source_id' => $this->tokenSource()->id,
        ]);

        Package::saving(function (Package $saving): void {
            if ($saving->webhook_id !== null) {
                throw new RuntimeException('The database went away.');
            }
        });

        $this->assertSame(WebhookCoverage::Failed, app(WebhookRegistrar::class)->register($package));

        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.github.com/repos/acme/widgets/hooks/8675309');

        $package->refresh();

        $this->assertNull($package->webhook_id);
        $this->assertNull($package->webhook_secret);
        $this->assertStringContainsString('database went away', (string) $package->webhook_error);
    }

    /**
     * A registry that has not set the app's webhook up is not a registry
     * whose packages should sit there waiting to be told about it.
     */
    public function test_an_app_installed_package_falls_back_to_its_own_webhook_when_no_secret_is_configured(): void
    {
        config(['services.github.app.webhook_secret' => null]);

        $this->fakeGitHub();

        $package = Package::factory()->create([
            'repository' => 'https://github.com/acme/widgets',
            'source_id' => $this->installedSource()->id,
        ]);

        $this->assertSame(WebhookCoverage::Repository, app(WebhookRegistrar::class)->register($package));

        $this->assertSame(8675309, $package->refresh()->webhook_id);
        $this->assertNull(app(WebhookRegistrar::class)->unmetRequirement($package));
    }

    /**
     * That fallback needs a permission the app may not have been given, and
     * the way out of it is the app's own webhook rather than the repository's.
     */
    public function test_an_app_installed_package_is_told_both_ways_out_when_the_fallback_is_refused(): void
    {
        config(['services.github.app.webhook_secret' => null]);

        Http::fake([
            'api.github.com/repos/*/hooks' => Http::response(['message' => 'Resource not accessible by integration'], 403),
            'api.github.com/app/installations/*/access_tokens' => Http::response([
                'token' => 'ghs_installation',
                'expires_at' => '2099-01-01T00:00:00Z',
            ]),
        ]);

        $package = Package::factory()->create([
            'repository' => 'https://github.com/acme/widgets',
            'source_id' => $this->installedSource()->id,
        ]);

        $this->assertSame(WebhookCoverage::Failed, app(WebhookRegistrar::class)->register($package));

        $requirement = (string) app(WebhookRegistrar::class)->unmetRequirement($package);

        $this->assertStringContainsString('GITHUB_APP_WEBHOOK_SECRET', $requirement);
        $this->assertStringContainsString('Webhooks: Read and write', $requirement);
    }

    /**
     * A hook that already exists keeps delivering whatever else is configured
     * later, so it must not be described as covered by something it is not.
     */
    public function test_a_package_with_its_own_hook_keeps_it_once_the_app_webhook_is_set_up(): void
    {
        $package = Package::factory()->create([
            'repository' => 'https://github.com/acme/widgets',
            'source_id' => Source::factory()->create(['account' => 'acme'])->id,
        ]);

        $package->forceFill(['webhook_id' => 8675309, 'webhook_secret' => 'secret'])->save();

        $this->assertSame(WebhookCoverage::Repository, $package->webhookCoverage());
    }

    public function test_a_new_package_syncs_on_push_unless_it_is_told_otherwise(): void
    {
        $this->assertTrue((new Package)->webhook_enabled);
        $this->assertTrue(Package::factory()->create()->webhook_enabled);
    }

    public function test_a_package_with_auto_sync_switched_off_has_no_webhook_arranged(): void
    {
        Http::fake();

        $package = Package::factory()->create([
            'repository' => 'https://github.com/acme/widgets',
            'source_id' => $this->tokenSource()->id,
            'webhook_enabled' => false,
        ]);

        $this->assertSame(WebhookCoverage::Disabled, $package->webhookCoverage());
        $this->assertSame(WebhookCoverage::Disabled, app(WebhookRegistrar::class)->reconcile($package));
        $this->assertNull($package->refresh()->webhook_id);

        // Off is off, not "left undone".
        $this->assertNull(app(WebhookRegistrar::class)->unmetRequirement($package));

        Http::assertNothingSent();
    }

    public function test_switching_auto_sync_off_removes_the_webhook_from_github(): void
    {
        $this->fakeGitHub();
        Http::fake(['api.github.com/repos/*/hooks/8675309' => Http::response(status: 204)]);

        $package = Package::factory()->create([
            'repository' => 'https://github.com/acme/widgets',
            'source_id' => $this->tokenSource()->id,
        ]);

        app(WebhookRegistrar::class)->reconcile($package);

        $this->assertSame(8675309, $package->refresh()->webhook_id);

        $package->forceFill(['webhook_enabled' => false])->save();

        $this->assertSame(WebhookCoverage::Disabled, app(WebhookRegistrar::class)->reconcile($package));

        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.github.com/repos/acme/widgets/hooks/8675309');

        $package->refresh();

        $this->assertNull($package->webhook_id);
        $this->assertNull($package->webhook_secret);
    }

    public function test_switching_auto_sync_on_again_sets_the_webhook_back_up(): void
    {
        $this->fakeGitHub();

        $package = Package::factory()->create([
            'repository' => 'https://github.com/acme/widgets',
            'source_id' => $this->tokenSource()->id,
            'webhook_enabled' => false,
        ]);

        $package->forceFill(['webhook_enabled' => true])->save();

        $this->assertSame(WebhookCoverage::Repository, app(WebhookRegistrar::class)->reconcile($package));
        $this->assertSame(8675309, $package->refresh()->webhook_id);
    }

    public function test_the_toggle_on_the_edit_page_creates_and_removes_the_webhook(): void
    {
        $this->fakeGitHub();
        Http::fake(['api.github.com/repos/*/hooks/8675309' => Http::response(status: 204)]);

        $this->actingAs(User::factory()->superAdmin()->create());

        $package = Package::factory()->create([
            'repository' => 'https://github.com/acme/widgets',
            'source_id' => $this->tokenSource()->id,
            'webhook_enabled' => false,
        ]);

        Livewire::test(EditPackage::class, ['record' => $package->getKey()])
            ->fillForm(['webhook_enabled' => true])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertSame(8675309, $package->refresh()->webhook_id);

        Livewire::test(EditPackage::class, ['record' => $package->getKey()])
            ->fillForm(['webhook_enabled' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $package->refresh();

        $this->assertFalse($package->webhook_enabled);
        $this->assertNull($package->webhook_id);

        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE');
    }

    /**
     * Editing anything else must not cost an API call, nor quietly retry a
     * webhook that failed for a reason nobody has fixed yet.
     */
    public function test_an_edit_that_leaves_the_toggle_alone_does_not_touch_github(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $package = Package::factory()->create([
            'repository' => 'https://github.com/acme/widgets',
            'source_id' => $this->tokenSource()->id,
        ]);

        Http::fake();

        Livewire::test(EditPackage::class, ['record' => $package->getKey()])
            ->fillForm(['description' => 'A better description.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('A better description.', $package->refresh()->description);

        Http::assertNothingSent();
    }

    public function test_creating_a_package_through_the_wizard_registers_its_webhook(): void
    {
        $this->fakeGitHub();
        $this->tokenSource();

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(CreatePackage::class)
            ->fillForm(['repository' => 'https://github.com/acme/widgets'])
            ->goToNextWizardStep()
            ->fillForm(['name' => 'acme/widgets'])
            ->call('create')
            ->assertHasNoFormErrors();

        $package = Package::query()->where('name', 'acme/widgets')->sole();

        $this->assertSame(8675309, $package->webhook_id);
    }

    public function test_deleting_a_package_removes_its_webhook_from_github(): void
    {
        Http::fake([
            'api.github.com/repos/*/hooks/*' => Http::response(status: 204),
        ]);

        $package = Package::factory()->create([
            'repository' => 'https://github.com/acme/widgets',
            'source_id' => $this->tokenSource()->id,
        ]);

        $package->forceFill(['webhook_id' => 8675309, 'webhook_secret' => 'secret'])->save();

        $package->delete();

        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.github.com/repos/acme/widgets/hooks/8675309');
    }

    /**
     * A hook someone already removed on GitHub leaves the same desired state
     * behind, so deleting the package must not be held up by it.
     */
    public function test_a_webhook_already_gone_does_not_stop_the_delete(): void
    {
        Http::fake([
            'api.github.com/repos/*/hooks/*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $package = Package::factory()->create([
            'repository' => 'https://github.com/acme/widgets',
            'source_id' => $this->tokenSource()->id,
        ]);

        $package->forceFill(['webhook_id' => 8675309, 'webhook_secret' => 'secret'])->save();

        $package->delete();

        $this->assertDatabaseMissing('packages', ['id' => $package->id]);
    }

    public function test_the_panel_offers_to_create_a_webhook_only_where_one_is_missing(): void
    {
        $this->fakeGitHub();

        $this->actingAs(User::factory()->superAdmin()->create());

        $covered = Package::factory()->create([
            'repository' => 'https://github.com/acme/widgets',
            'source_id' => Source::factory()->create(['account' => 'acme'])->id,
        ]);

        $uncovered = Package::factory()->create([
            'repository' => 'https://github.com/other/gizmos',
            'source_id' => $this->tokenSource('other')->id,
        ]);

        Livewire::test(ViewPackage::class, ['record' => $covered->getKey()])
            ->assertActionHidden(TestAction::make('createWebhook'));

        Livewire::test(ViewPackage::class, ['record' => $uncovered->getKey()])
            ->assertActionVisible(TestAction::make('createWebhook'))
            ->callAction(TestAction::make('createWebhook'))
            ->assertNotified();

        $this->assertSame(8675309, $uncovered->refresh()->webhook_id);
    }
}
