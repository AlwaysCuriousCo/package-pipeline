<?php

namespace Tests\Feature;

use App\Enums\WebhookCoverage;
use App\Filament\Resources\Packages\Pages\CreatePackage;
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
use Tests\TestCase;

class PackageWebhookRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.github.app.webhook_secret' => 'the-app-webhook-secret']);
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
        ]);
    }

    private function tokenSource(string $account = 'acme'): Source
    {
        return Source::factory()->withToken()->create(['account' => $account]);
    }

    public function test_a_package_under_an_installed_app_needs_no_webhook_of_its_own(): void
    {
        Http::fake();

        $package = Package::factory()->create([
            'repository' => 'https://github.com/acme/widgets',
            'source_id' => Source::factory()->create(['account' => 'acme'])->id,
        ]);

        $this->assertSame(WebhookCoverage::Application, $package->webhookCoverage());
        $this->assertSame(WebhookCoverage::Application, app(WebhookRegistrar::class)->register($package));
        $this->assertNull($package->refresh()->webhook_id);

        // Creating a second hook on the repository would only sync it twice.
        Http::assertNothingSent();
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

    public function test_an_app_covered_package_is_flagged_when_no_secret_is_configured_here(): void
    {
        config(['services.github.app.webhook_secret' => null]);

        $package = Package::factory()->create([
            'repository' => 'https://github.com/acme/widgets',
            'source_id' => Source::factory()->create(['account' => 'acme'])->id,
        ]);

        $this->assertSame(WebhookCoverage::Unconfigured, $package->webhookCoverage());
        $this->assertStringContainsString(
            'GITHUB_APP_WEBHOOK_SECRET',
            (string) app(WebhookRegistrar::class)->unmetRequirement($package),
        );
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
