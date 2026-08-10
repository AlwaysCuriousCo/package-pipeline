<?php

namespace Tests\Feature;

use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\OutgoingWebhook;
use App\Models\Package;
use App\Notifications\PackageSyncFailed;
use App\Notifications\PackageVersionsPublished;
use App\Services\AdminNotifier;
use App\Services\SyncOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Outgoing webhooks: what this registry POSTs, to whom, and what it does when
 * the receiver is not there.
 *
 * @see docs/outgoing-webhooks.md
 */
class OutgoingWebhookTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Intercept the deliveries and nothing else.
     *
     * A blanket Queue::fake() would swallow the notification itself — these
     * notifications are ShouldQueue, so `notify()` pushes a job rather than
     * running the channel — and the fan-out under test would never happen. A
     * partial fake leaves that job on the suite's sync connection, where it
     * runs inline, and catches only the HTTP delivery at the end of it.
     *
     * @param  list<class-string>  $jobs
     */
    private function fakeDeliveries(array $jobs = [DeliverWebhook::class]): void
    {
        Queue::fake($jobs);
    }

    private function package(): Package
    {
        return Package::factory()->create([
            'name' => 'acme/widgets',
            'repository' => 'https://github.com/acme/widgets',
            'latest_version' => '1.1.0',
        ]);
    }

    private function announceRelease(?Package $package = null): void
    {
        app(AdminNotifier::class)->send(new PackageVersionsPublished(
            $package ?? $this->package(),
            new SyncOutcome(releases: ['1.1.0'], devVersions: [], removed: [], initialImport: false, total: 4),
        ));
    }

    /**
     * The one delivery that was queued, so a test can run it and inspect what
     * went out.
     */
    private function queued(): DeliverWebhook
    {
        $jobs = [];

        Queue::assertPushed(DeliverWebhook::class, function (DeliverWebhook $job) use (&$jobs): bool {
            $jobs[] = $job;

            return true;
        });

        $this->assertCount(1, $jobs, 'Exactly one delivery should have been queued.');

        return $jobs[0];
    }

    public function test_a_subscribed_endpoint_is_sent_the_event(): void
    {
        $this->fakeDeliveries();

        $webhook = OutgoingWebhook::factory()->subscribedTo(WebhookEvent::VersionPublished)->create();

        $this->announceRelease();

        Queue::assertPushed(
            DeliverWebhook::class,
            fn (DeliverWebhook $job): bool => $job->webhook->is($webhook)
                && $job->event === WebhookEvent::VersionPublished,
        );
    }

    public function test_an_endpoint_subscribed_to_other_events_hears_nothing(): void
    {
        $this->fakeDeliveries();

        OutgoingWebhook::factory()->subscribedTo(WebhookEvent::SyncFailed)->create();

        $this->announceRelease();

        Queue::assertNotPushed(DeliverWebhook::class);
    }

    public function test_an_inactive_endpoint_hears_nothing(): void
    {
        $this->fakeDeliveries();

        OutgoingWebhook::factory()->inactive()->create();

        $this->announceRelease();

        Queue::assertNotPushed(DeliverWebhook::class);
    }

    /**
     * Two endpoints are two deliveries with two secrets and two outcomes; one
     * being down must have no bearing on the other.
     */
    public function test_every_subscribed_endpoint_gets_its_own_delivery(): void
    {
        $this->fakeDeliveries();

        OutgoingWebhook::factory()->count(3)->create();

        $this->announceRelease();

        Queue::assertPushed(DeliverWebhook::class, 3);
    }

    /**
     * The whole point of the signature: what this app sends has to verify with
     * the same three lines GitHubWebhookController checks an incoming delivery
     * with. This is that check, spelled out.
     */
    public function test_a_delivery_is_signed_over_the_raw_body_exactly_as_github_signs_ours(): void
    {
        $this->fakeDeliveries();
        Http::fake();

        OutgoingWebhook::factory()->create(['secret' => 'a-shared-secret']);

        $this->announceRelease();

        $this->queued()->handle();

        Http::assertSent(function (Request $request): bool {
            $expected = 'sha256='.hash_hmac('sha256', $request->body(), 'a-shared-secret');

            return hash_equals($expected, $request->header('X-Hub-Signature-256')[0]);
        });
    }

    public function test_an_endpoint_with_no_secret_is_sent_an_unsigned_delivery(): void
    {
        $this->fakeDeliveries();
        Http::fake();

        OutgoingWebhook::factory()->unsigned()->create();

        $this->announceRelease();

        $this->queued()->handle();

        Http::assertSent(fn (Request $request): bool => $request->header('X-Hub-Signature-256') === []);
    }

    public function test_a_delivery_carries_the_event_and_a_stable_delivery_id(): void
    {
        $this->fakeDeliveries();
        Http::fake();

        OutgoingWebhook::factory()->create();

        $this->announceRelease();

        $job = $this->queued();

        $job->handle();

        Http::assertSent(function (Request $request) use ($job): bool {
            return $request->header('X-Package-Pipeline-Event') === [WebhookEvent::VersionPublished->value]
                && $request->header('X-Package-Pipeline-Delivery') === [$job->delivery]
                && $request->data()['delivery'] === $job->delivery
                && $request->data()['event'] === WebhookEvent::VersionPublished->value;
        });
    }

    public function test_the_release_payload_carries_what_a_deploy_pipeline_needs(): void
    {
        $this->fakeDeliveries();
        Http::fake();

        OutgoingWebhook::factory()->create();

        $this->announceRelease();

        $this->queued()->handle();

        Http::assertSent(function (Request $request): bool {
            $data = $request->data()['data'];

            return $data['package'] === 'acme/widgets'
                && $data['releases'] === ['1.1.0']
                && $data['latest'] === '1.1.0'
                && $data['initial_import'] === false
                && $data['source_url'] === 'https://github.com/acme/widgets';
        });
    }

    public function test_a_successful_delivery_is_recorded(): void
    {
        $this->fakeDeliveries();
        Http::fake(fn () => Http::response('', 204));

        $webhook = OutgoingWebhook::factory()->create(['consecutive_failures' => 4]);

        $this->announceRelease();

        $this->queued()->handle();

        $webhook->refresh();

        $this->assertSame(204, $webhook->last_status);
        $this->assertNotNull($webhook->last_delivered_at);
        $this->assertNull($webhook->last_error);
        // A delivery that got through says the endpoint is back, whatever it
        // was doing before.
        $this->assertSame(0, $webhook->consecutive_failures);
    }

    public function test_a_refused_delivery_records_the_status_and_the_reason(): void
    {
        $this->fakeDeliveries();
        Http::fake(fn () => Http::response('no such hook', 404));

        $webhook = OutgoingWebhook::factory()->create();

        $this->announceRelease();

        $this->queued()->handle();

        $webhook->refresh();

        $this->assertSame(404, $webhook->last_status);
        $this->assertStringContainsString('404', (string) $webhook->last_error);
        $this->assertStringContainsString('no such hook', (string) $webhook->last_error);
        $this->assertSame(1, $webhook->consecutive_failures);
    }

    /**
     * A receiver that never answers is the case this feature is most likely to
     * meet, and the one that must cost the registry nothing.
     */
    public function test_an_unreachable_endpoint_is_recorded_rather_than_thrown(): void
    {
        $this->fakeDeliveries();
        Http::fake(fn () => throw new ConnectionException('Connection timed out.'));

        $webhook = OutgoingWebhook::factory()->create();

        $this->announceRelease();

        // No exception: the job succeeds and there is no failed_jobs row for a
        // sync to be blamed for.
        $this->queued()->handle();

        $webhook->refresh();

        $this->assertNull($webhook->last_status);
        $this->assertStringContainsString('Connection timed out.', (string) $webhook->last_error);
        $this->assertSame(1, $webhook->consecutive_failures);
    }

    /**
     * Switching an endpoint off has to stop the deliveries already sitting on
     * the queue, not only the ones dispatched afterwards.
     */
    public function test_an_endpoint_switched_off_after_dispatch_is_not_posted_to(): void
    {
        $this->fakeDeliveries();
        Http::fake();

        $webhook = OutgoingWebhook::factory()->create();

        $this->announceRelease();

        $job = $this->queued();

        $webhook->update(['active' => false]);

        $job->handle();

        Http::assertNothingSent();
    }

    public function test_a_sync_failure_reaches_a_subscribed_endpoint(): void
    {
        $this->fakeDeliveries();
        Http::fake();

        OutgoingWebhook::factory()->subscribedTo(WebhookEvent::SyncFailed)->create();

        app(AdminNotifier::class)->send(new PackageSyncFailed($this->package(), 'GitHub timed out.'));

        $this->queued()->handle();

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $payload['event'] === 'sync.failed'
                && $payload['data']['package'] === 'acme/widgets'
                // Whole, not squished to a line as the bell shows it.
                && $payload['data']['reason'] === 'GitHub timed out.';
        });
    }

    public function test_abandoning_a_package_announces_it_once(): void
    {
        $this->fakeDeliveries();

        OutgoingWebhook::factory()->subscribedTo(WebhookEvent::PackageAbandoned)->create();

        $package = $this->package();

        $package->update(['abandoned' => true, 'replacement_package' => 'acme/gadgets']);

        Queue::assertPushed(
            DeliverWebhook::class,
            fn (DeliverWebhook $job): bool => $job->event === WebhookEvent::PackageAbandoned
                && $job->payload['package'] === 'acme/widgets'
                && $job->payload['replacement'] === 'acme/gadgets',
        );

        // The flag is re-saved unchanged on every sync; saying so again each
        // hour would make the subscription useless.
        $package->update(['description' => 'Still abandoned.']);

        Queue::assertPushed(DeliverWebhook::class, 1);
    }

    public function test_un_abandoning_a_package_announces_nothing(): void
    {
        $this->fakeDeliveries();

        OutgoingWebhook::factory()->subscribedTo(WebhookEvent::PackageAbandoned)->create();

        $package = $this->package();

        $package->update(['abandoned' => true]);

        Queue::assertPushed(DeliverWebhook::class, 1);

        // Good news, and nobody has to be paged for a warning being withdrawn.
        $package->update(['abandoned' => false]);

        Queue::assertPushed(DeliverWebhook::class, 1);
    }

    /**
     * A package that arrives abandoned never told anyone anything different, so
     * there is no change to announce — which also keeps a bulk import of dead
     * packages from paging everyone once per row.
     */
    public function test_a_package_created_already_abandoned_announces_nothing(): void
    {
        $this->fakeDeliveries();

        OutgoingWebhook::factory()->subscribedTo(WebhookEvent::PackageAbandoned)->create();

        Package::factory()->create(['abandoned' => true]);

        Queue::assertNotPushed(DeliverWebhook::class);
    }

    /**
     * Ping is addressed to one endpoint by hand, so it is deliberately not
     * something an endpoint can subscribe to — and never something the registry
     * sends by itself.
     */
    public function test_ping_is_not_a_subscribable_event(): void
    {
        $this->assertNotContains(WebhookEvent::Ping, WebhookEvent::subscribable());

        $this->fakeDeliveries();

        OutgoingWebhook::factory()->create(['events' => ['ping']]);

        $this->announceRelease();

        Queue::assertNotPushed(DeliverWebhook::class);
    }

    /**
     * An events array holding a value no release of this app names any more
     * must degrade to "not subscribed", not to a crash inside an unrelated sync.
     */
    public function test_an_unknown_stored_event_is_ignored(): void
    {
        $this->fakeDeliveries();

        OutgoingWebhook::factory()->create(['events' => ['version.published', 'something.retired']]);

        $this->announceRelease();

        Queue::assertPushed(DeliverWebhook::class, 1);

        $this->assertSame(
            [WebhookEvent::VersionPublished],
            OutgoingWebhook::query()->sole()->subscribedEvents(),
        );
    }
}
