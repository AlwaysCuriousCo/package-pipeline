<?php

namespace Tests\Feature;

use App\Jobs\SyncPackageJob;
use App\Models\Package;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * GitLab's leg of the incoming webhooks, which authenticates by replaying the
 * hook's secret in a header rather than signing the body. Only the
 * per-repository route exists, so the package is named by the URL and the
 * secret it is checked against is that package's own.
 */
class GitLabWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'the-hook-secret';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * A package carrying its own GitLab hook, which is the only shape of
     * package this endpoint answers for.
     */
    private function package(string $name = 'group/widgets', string $secret = self::SECRET): Package
    {
        $package = Package::factory()->create([
            'name' => $name,
            'repository' => "https://gitlab.com/{$name}",
        ]);

        $package->forceFill(['webhook_id' => 77, 'webhook_secret' => $secret])->save();

        return $package;
    }

    /**
     * Post a delivery the way GitLab does: the hook's secret verbatim in
     * X-Gitlab-Token and the event name in X-Gitlab-Event.
     *
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function deliver(
        Package $package,
        string $event = 'Push Hook',
        ?string $token = self::SECRET,
        array $payload = ['ref' => 'refs/heads/main'],
    ): TestResponse {
        $headers = $token === null ? [] : ['X-Gitlab-Token' => $token];

        return $this->postJson(
            route('webhooks.gitlab.package', $package),
            $payload,
            $headers + ['X-Gitlab-Event' => $event],
        );
    }

    public function test_a_push_queues_a_sync_for_the_package_the_hook_belongs_to(): void
    {
        $package = $this->package();

        $this->deliver($package)
            ->assertAccepted()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonPath('package', 'group/widgets');

        Queue::assertPushed(
            SyncPackageJob::class,
            fn (SyncPackageJob $job): bool => $job->package->is($package),
        );
    }

    public function test_a_tag_push_queues_a_sync(): void
    {
        $package = $this->package();

        $this->deliver($package, 'Tag Push Hook', payload: ['ref' => 'refs/tags/v1.0.0'])
            ->assertAccepted();

        Queue::assertPushed(SyncPackageJob::class);
    }

    public function test_a_delivery_is_recorded_on_the_package(): void
    {
        $package = $this->package();

        $this->assertNull($package->webhook_received_at);

        $this->deliver($package)->assertAccepted();

        $this->assertNotNull($package->refresh()->webhook_received_at);
    }

    /**
     * `git push --tags` over ten tags is ten deliveries in about a second. The
     * delay keeps the job queued long enough for the uniqueness lock to fold
     * the rest of the burst into the one sync that reads the whole repository
     * anyway — the same debounce GitHub's deliveries get.
     */
    public function test_a_burst_of_pushes_becomes_a_single_delayed_sync(): void
    {
        $package = $this->package();

        foreach (['v1.1.0', 'v1.1.1', 'v1.2.0'] as $tag) {
            $this->deliver($package, 'Tag Push Hook', payload: ['ref' => "refs/tags/{$tag}"])
                ->assertAccepted();
        }

        Queue::assertPushed(SyncPackageJob::class, 1);
        Queue::assertPushed(
            SyncPackageJob::class,
            fn (SyncPackageJob $job): bool => $job->delay !== null,
        );
    }

    /**
     * The lock that debounces a burst is per package, so two projects pushed
     * at once must not collapse into one sync.
     */
    public function test_pushes_to_different_packages_each_queue_their_own_sync(): void
    {
        $this->deliver($this->package())->assertAccepted();
        $this->deliver($this->package('group/gadgets'))->assertAccepted();

        Queue::assertPushed(SyncPackageJob::class, 2);
    }

    public function test_a_delivery_with_the_wrong_token_is_rejected(): void
    {
        $package = $this->package();

        $this->deliver($package, token: 'not-the-secret')
            ->assertUnauthorized()
            ->assertJsonPath('status', 'rejected');

        Queue::assertNothingPushed();
        $this->assertNull($package->refresh()->webhook_received_at);
    }

    /**
     * The token is the whole of the authentication, so a delivery that brings
     * no header at all cannot be treated as one that brought a blank secret.
     */
    public function test_a_delivery_carrying_no_token_header_is_rejected(): void
    {
        $package = $this->package();

        $this->deliver($package, token: null)->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    public function test_a_delivery_carrying_an_empty_token_header_is_rejected(): void
    {
        $package = $this->package();

        $this->deliver($package, token: '')->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    /**
     * A hook copied onto another project would otherwise sync a package from
     * commits that are not its own. GitLab's payload is not authenticated, so
     * the only thing standing between the two packages is that each hook's
     * secret is checked against the package its URL names.
     */
    public function test_one_packages_hook_cannot_sync_another(): void
    {
        $this->package('group/widgets', 'widgets-secret');
        $gadgets = $this->package('group/gadgets', 'gadgets-secret');

        $this->deliver($gadgets, token: 'widgets-secret')->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    /**
     * GitLab hooks are subscribed to whole event categories, and a project
     * hook created here can be widened by hand in GitLab's own UI afterwards.
     */
    public function test_an_event_nothing_acts_on_is_acknowledged_and_dropped(): void
    {
        $package = $this->package();

        $this->deliver($package, 'Issue Hook')
            ->assertAccepted()
            ->assertJsonPath('status', 'ignored');

        Queue::assertNothingPushed();
        $this->assertNull($package->refresh()->webhook_received_at);
    }

    public function test_a_delivery_naming_no_event_at_all_is_dropped(): void
    {
        $package = $this->package();

        $this->deliver($package, event: '')
            ->assertAccepted()
            ->assertJsonPath('status', 'ignored');

        Queue::assertNothingPushed();
    }

    /**
     * An unauthenticated caller must not learn which events this endpoint
     * acts on, so the token is answered before the event is even read.
     */
    public function test_the_token_is_checked_before_the_event_is(): void
    {
        $package = $this->package();

        $this->deliver($package, 'Issue Hook', token: 'not-the-secret')->assertUnauthorized();
    }

    public function test_the_route_is_closed_for_a_package_that_has_no_hook(): void
    {
        $package = Package::factory()->create(['repository' => 'https://gitlab.com/group/widgets']);

        $this->deliver($package)->assertNotFound();

        Queue::assertNothingPushed();
    }

    /**
     * A blank secret must never be something a caller can satisfy by sending
     * a blank token — hash_equals('', '') is true.
     */
    public function test_an_empty_secret_is_not_a_secret_anyone_can_match(): void
    {
        $package = Package::factory()->create(['repository' => 'https://gitlab.com/group/widgets']);
        $package->forceFill(['webhook_id' => 77, 'webhook_secret' => ''])->save();

        $this->deliver($package, token: '')->assertNotFound();

        Queue::assertNothingPushed();
    }

    /**
     * A package that has switched auto-sync off keeps its hook until the
     * registrar gets to it, and GitLab keeps delivering meanwhile.
     */
    public function test_a_package_with_auto_sync_switched_off_is_ignored(): void
    {
        $package = $this->package();
        $package->forceFill(['webhook_enabled' => false])->save();

        $this->deliver($package)
            ->assertAccepted()
            ->assertJsonPath('status', 'ignored');

        Queue::assertNothingPushed();
    }

    /**
     * GitLab posts with no session cookie and no token, so the endpoint has to
     * sit outside CSRF protection, with the hook secret standing in for it.
     *
     * The middleware never runs under test, so the exclusion is asserted on
     * the middleware itself — otherwise this only breaks in production.
     */
    public function test_the_endpoint_is_excluded_from_csrf_protection(): void
    {
        $package = $this->package();

        $this->assertStringStartsWith(
            '/incoming/',
            parse_url(route('webhooks.gitlab.package', $package), PHP_URL_PATH),
        );

        $this->assertContains(
            'incoming/*',
            (new PreventRequestForgery($this->app, $this->app['encrypter']))->getExcludedPaths(),
        );
    }
}
