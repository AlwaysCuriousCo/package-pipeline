<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\GitHubAppRegistration;
use App\Services\GitHub\GitHubApp;
use Database\Factories\GitHubAppRegistrationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Where the app's identity comes from: the environment when it names an app,
 * otherwise an app registered from the panel.
 */
class GitHubAppCredentialsTest extends TestCase
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

    public function test_nothing_is_configured_without_either(): void
    {
        $app = app(GitHubApp::class);

        $this->assertFalse($app->isConfigured());
        $this->assertFalse($app->configuredFromEnvironment());
        $this->assertNull($app->webhookSecret());
    }

    public function test_an_app_registered_from_the_panel_is_used_when_the_environment_names_none(): void
    {
        $registration = GitHubAppRegistration::factory()->create(['slug' => 'acme-pipeline']);

        Http::fake(['api.github.com/app/installations/42' => Http::response(['account' => ['login' => 'acme']])]);

        $app = app(GitHubApp::class);

        $this->assertTrue($app->isConfigured());
        $this->assertFalse($app->configuredFromEnvironment());
        $this->assertSame($registration->webhook_secret, $app->webhookSecret());

        // The stored slug saves the lookup, exactly as GITHUB_APP_SLUG does.
        $this->assertStringStartsWith('https://github.com/apps/acme-pipeline/installations/new', $app->installationUrl('state'));

        $app->installation(42);

        Http::assertSent(fn (Request $request): bool => $this->issuer($request) === $registration->app_id);
    }

    public function test_the_environment_wins_over_a_registered_app(): void
    {
        $registration = GitHubAppRegistration::factory()->create();

        config()->set('services.github.app.id', '777');
        config()->set('services.github.app.private_key', GitHubAppRegistrationFactory::privateKey());

        Http::fake(['api.github.com/app/installations/42' => Http::response(['account' => ['login' => 'acme']])]);

        $app = app(GitHubApp::class);

        $this->assertTrue($app->configuredFromEnvironment());
        $this->assertNull($app->registration());

        // The registered app's secret belongs to its own webhook, not to
        // whichever app the environment names.
        $this->assertNull($app->webhookSecret());

        $app->installation(42);

        Http::assertSent(fn (Request $request): bool => $this->issuer($request) === '777');
        Http::assertNotSent(fn (Request $request): bool => $this->issuer($request) === $registration->app_id);
    }

    public function test_a_half_configured_environment_does_not_fall_back_to_the_registered_app(): void
    {
        GitHubAppRegistration::factory()->create();

        config()->set('services.github.app.id', '777');

        $this->assertFalse(app(GitHubApp::class)->isConfigured());
    }

    public function test_the_environments_webhook_secret_wins_over_a_registered_one(): void
    {
        GitHubAppRegistration::factory()->create(['webhook_secret' => 'stored']);

        config()->set('services.github.app.webhook_secret', 'from-env');

        $this->assertSame('from-env', app(GitHubApp::class)->webhookSecret());
    }

    public function test_describing_an_app_confirms_the_key_belongs_to_it(): void
    {
        Http::fake(['api.github.com/app' => Http::response(['id' => 123456, 'slug' => 'acme-pipeline'])]);

        $app = app(GitHubApp::class)->describe('123456', GitHubAppRegistrationFactory::privateKey());

        $this->assertSame('acme-pipeline', $app['slug']);

        Http::assertSent(fn (Request $request): bool => $this->issuer($request) === '123456');
    }

    public function test_describing_an_app_with_another_apps_key_fails(): void
    {
        Http::fake(['api.github.com/app' => Http::response(['message' => 'A JSON web token could not be decoded'], 401)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GitHub did not accept that private key for app 123456');

        app(GitHubApp::class)->describe('123456', GitHubAppRegistrationFactory::privateKey());
    }

    public function test_describing_an_app_that_answers_as_another_fails(): void
    {
        Http::fake(['api.github.com/app' => Http::response(['id' => 999, 'slug' => 'someone-else'])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not belong to app 123456');

        app(GitHubApp::class)->describe('123456', GitHubAppRegistrationFactory::privateKey());
    }

    public function test_the_key_and_secret_are_encrypted_at_rest_and_never_audited(): void
    {
        $registration = GitHubAppRegistration::factory()->create(['webhook_secret' => 'the-webhook-secret']);

        $row = (array) $registration->getConnection()->table('github_app_registrations')->sole();

        $this->assertStringNotContainsString('PRIVATE KEY', $row['private_key']);
        $this->assertNotSame('the-webhook-secret', $row['webhook_secret']);

        $entry = Activity::query()->where('subject_type', GitHubAppRegistration::class)->sole();

        $this->assertSame($registration->app_id, $entry->properties['attributes']['app_id']);
        $this->assertArrayNotHasKey('private_key', $entry->properties['attributes']);
        $this->assertArrayNotHasKey('webhook_secret', $entry->properties['attributes']);
    }
}
