<?php

namespace Tests\Feature;

use App\Jobs\SyncPackageJob;
use App\Models\Package;
use App\Models\Source;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GitHubWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const APP_SECRET = 'the-app-webhook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.github.app.webhook_secret' => self::APP_SECRET]);

        Queue::fake();
    }

    private function package(string $repository = 'https://github.com/acme/widgets'): Package
    {
        return Package::factory()->create([
            'name' => 'acme/widgets',
            'repository' => $repository,
            'source_id' => Source::factory()->create(['account' => 'acme'])->id,
        ]);
    }

    /**
     * Post a delivery the way GitHub does: a raw JSON body, an event header,
     * and a signature over exactly the bytes sent.
     *
     * @param  array<string, mixed>  $payload
     */
    private function deliver(string $event, array $payload = [], ?string $secret = self::APP_SECRET, ?string $url = null): TestResponse
    {
        $body = json_encode($payload);

        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
        ];

        if ($secret !== null) {
            $headers['HTTP_X_HUB_SIGNATURE_256'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', $url ?? route('webhooks.github'), [], [], [], $headers, $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function push(string $repository = 'acme/widgets', string $ref = 'refs/tags/v1.1.0'): array
    {
        return [
            'ref' => $ref,
            'repository' => ['full_name' => $repository],
        ];
    }

    public function test_a_push_queues_a_sync_for_the_package_it_names(): void
    {
        $package = $this->package();

        $this->deliver('push', $this->push())
            ->assertAccepted()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('package', 'acme/widgets');

        Queue::assertPushed(
            SyncPackageJob::class,
            fn (SyncPackageJob $job): bool => $job->package->is($package),
        );
    }

    public function test_a_delivery_is_recorded_on_the_package(): void
    {
        $package = $this->package();

        $this->assertNull($package->webhook_received_at);

        $this->deliver('push', $this->push())->assertAccepted();

        $this->assertNotNull($package->refresh()->webhook_received_at);
    }

    public function test_a_created_branch_queues_a_sync(): void
    {
        $this->package();

        $this->deliver('create', [
            'ref' => 'release/2.x',
            'ref_type' => 'branch',
            'repository' => ['full_name' => 'acme/widgets'],
        ])->assertAccepted();

        Queue::assertPushed(SyncPackageJob::class);
    }

    /**
     * A version that has gone upstream has to go here too, and the sync prunes
     * whatever it no longer finds.
     */
    public function test_a_deleted_tag_queues_a_sync(): void
    {
        $this->package();

        $this->deliver('delete', [
            'ref' => 'v1.0.0',
            'ref_type' => 'tag',
            'repository' => ['full_name' => 'acme/widgets'],
        ])->assertAccepted();

        Queue::assertPushed(SyncPackageJob::class);
    }

    /**
     * `git push --tags` over ten tags is ten deliveries in about a second. The
     * delay keeps the job queued long enough for the uniqueness lock to fold
     * the rest of the burst into the one sync that reads the whole repository
     * anyway.
     */
    public function test_a_burst_of_pushes_becomes_a_single_delayed_sync(): void
    {
        $this->package();

        foreach (['v1.1.0', 'v1.1.1', 'v1.2.0'] as $tag) {
            $this->deliver('push', $this->push(ref: "refs/tags/{$tag}"))->assertAccepted();
        }

        Queue::assertPushed(SyncPackageJob::class, 1);
        Queue::assertPushed(
            SyncPackageJob::class,
            fn (SyncPackageJob $job): bool => $job->delay !== null,
        );
    }

    /**
     * Two packages pushed at once must not collapse into one sync — the lock
     * that debounces a burst is per package.
     */
    public function test_pushes_to_different_packages_each_queue_their_own_sync(): void
    {
        $this->package();

        Package::factory()->create([
            'name' => 'acme/gadgets',
            'repository' => 'https://github.com/acme/gadgets',
        ]);

        $this->deliver('push', $this->push())->assertAccepted();
        $this->deliver('push', $this->push(repository: 'acme/gadgets'))->assertAccepted();

        Queue::assertPushed(SyncPackageJob::class, 2);
    }

    public function test_a_forged_signature_is_rejected(): void
    {
        $this->package();

        $this->deliver('push', $this->push(), secret: 'not-the-secret')
            ->assertUnauthorized()
            ->assertJsonPath('status', 'rejected');

        Queue::assertNothingPushed();
    }

    public function test_an_unsigned_delivery_is_rejected(): void
    {
        $this->package();

        $this->deliver('push', $this->push(), secret: null)->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    /**
     * The body is what GitHub signs, so a payload altered in flight must fail
     * even though the signature itself is well formed.
     */
    public function test_a_tampered_body_is_rejected(): void
    {
        $this->package();

        $body = json_encode($this->push());
        $signature = 'sha256='.hash_hmac('sha256', $body, self::APP_SECRET);

        $this->call('POST', route('webhooks.github'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], json_encode($this->push(ref: 'refs/tags/v9.9.9')))
            ->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    public function test_a_ping_is_answered_without_syncing_anything(): void
    {
        $this->package();

        $this->deliver('ping', ['zen' => 'Design for failure.'])->assertNoContent();

        Queue::assertNothingPushed();
    }

    public function test_an_event_nothing_acts_on_is_acknowledged_and_dropped(): void
    {
        $this->package();

        $this->deliver('issues', $this->push())
            ->assertAccepted()
            ->assertJsonPath('status', 'ignored');

        Queue::assertNothingPushed();
    }

    /**
     * The app's webhook hears from every repository shared with every
     * installation, and most of them are not packages here.
     */
    public function test_a_repository_that_is_not_a_package_is_ignored(): void
    {
        $this->package();

        $this->deliver('push', $this->push(repository: 'acme/something-else'))
            ->assertAccepted()
            ->assertJsonPath('status', 'ignored');

        Queue::assertNothingPushed();
    }

    /**
     * A prefix match would let a push to the wrong repository sync a package.
     */
    public function test_a_repository_whose_name_merely_starts_the_same_is_not_matched(): void
    {
        $this->package();

        $this->deliver('push', $this->push(repository: 'acme/widgets-pro'))
            ->assertAccepted()
            ->assertJsonPath('status', 'ignored');

        Queue::assertNothingPushed();
    }

    public function test_a_package_stored_as_a_bare_path_is_still_matched(): void
    {
        $package = $this->package(repository: 'acme/widgets');

        $this->deliver('push', $this->push())->assertAccepted();

        Queue::assertPushed(
            SyncPackageJob::class,
            fn (SyncPackageJob $job): bool => $job->package->is($package),
        );
    }

    /**
     * The repository column is unique as typed, not as parsed, so the same
     * repository stored as a browser URL and as an SSH remote is two packages.
     * A push must reach both — first-match would sync one arbitrarily and
     * silently starve the other.
     */
    public function test_every_package_storing_the_pushed_repository_is_synced(): void
    {
        $https = $this->package();

        $ssh = Package::factory()->create([
            'name' => 'acme/widgets-mirror',
            'repository' => 'git@github.com:Acme/Widgets.git',
        ]);

        $this->deliver('push', $this->push())->assertAccepted();

        Queue::assertPushed(SyncPackageJob::class, 2);

        foreach ([$https, $ssh] as $package) {
            Queue::assertPushed(
                SyncPackageJob::class,
                fn (SyncPackageJob $job): bool => $job->package->is($package),
            );

            $this->assertNotNull($package->refresh()->webhook_received_at);
        }
    }

    public function test_the_repository_being_created_publishes_nothing(): void
    {
        $this->package();

        $this->deliver('create', [
            'ref_type' => 'repository',
            'repository' => ['full_name' => 'acme/widgets'],
        ])->assertAccepted()->assertJsonPath('status', 'ignored');

        Queue::assertNothingPushed();
    }

    public function test_deliveries_are_refused_when_no_secret_is_configured(): void
    {
        config(['services.github.app.webhook_secret' => null]);

        $this->package();

        $this->deliver('push', $this->push())->assertServiceUnavailable();

        Queue::assertNothingPushed();
    }

    public function test_a_repository_hook_is_verified_against_that_packages_own_secret(): void
    {
        $package = $this->package();
        $package->forceFill(['webhook_id' => 42, 'webhook_secret' => 'per-repo-secret'])->save();

        $url = route('webhooks.github.package', $package);

        // The app's secret has no authority over a repository hook...
        $this->deliver('push', $this->push(), url: $url)->assertUnauthorized();

        // ...and the package's own does.
        $this->deliver('push', $this->push(), secret: 'per-repo-secret', url: $url)->assertAccepted();

        Queue::assertPushed(SyncPackageJob::class, 1);
    }

    public function test_a_repository_hook_route_is_closed_for_a_package_that_has_none(): void
    {
        $package = $this->package();

        $this->deliver('push', $this->push(), url: route('webhooks.github.package', $package))
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    /**
     * A hook copied onto the wrong repository would otherwise sync a package
     * from commits that are not its own.
     */
    public function test_a_repository_hook_posting_for_another_repository_is_refused(): void
    {
        $package = $this->package();
        $package->forceFill(['webhook_id' => 42, 'webhook_secret' => 'per-repo-secret'])->save();

        $this->deliver(
            'push',
            $this->push(repository: 'acme/other'),
            secret: 'per-repo-secret',
            url: route('webhooks.github.package', $package),
        )->assertUnprocessable();

        Queue::assertNothingPushed();
    }

    public function test_a_payload_naming_no_repository_is_refused(): void
    {
        $this->package();

        $this->deliver('push', ['ref' => 'refs/tags/v1.0.0'])->assertUnprocessable();

        Queue::assertNothingPushed();
    }

    /**
     * GitHub posts with no session cookie and no token, so the endpoint has to
     * sit outside CSRF protection, with the signature standing in for it.
     *
     * The middleware never runs under test, so the exclusion is asserted on
     * the middleware itself — otherwise this only breaks in production.
     */
    public function test_the_endpoint_is_excluded_from_csrf_protection(): void
    {
        $this->assertContains(
            'incoming/*',
            (new PreventRequestForgery($this->app, $this->app['encrypter']))->getExcludedPaths(),
        );
    }
}
