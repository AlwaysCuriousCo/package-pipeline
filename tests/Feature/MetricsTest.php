<?php

namespace Tests\Feature;

use App\Models\MirroredArchive;
use App\Models\MirroredPackage;
use App\Models\OutgoingWebhook;
use App\Models\Package;
use App\Models\Upstream;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Prometheus scrape endpoint.
 *
 * @see docs/metrics.md
 */
class MetricsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'registry.metrics.token' => null,
            // Every test here wants a fresh reading; the caching is asserted
            // on its own below.
            'registry.metrics.cache_seconds' => 0,
        ]);
    }

    /**
     * Scrape, turning the endpoint on first — which is the state every test
     * below the first one is about.
     *
     * @return TestResponse<Response>
     */
    private function scrape(): TestResponse
    {
        config(['registry.metrics.enabled' => true]);

        return $this->get('/metrics');
    }

    /**
     * The value of one sample, or null when the series is absent — which is a
     * state this endpoint uses deliberately and a test has to be able to see.
     */
    private function sample(string $body, string $name): ?string
    {
        return preg_match('/^package_pipeline_'.preg_quote($name, '/').'(?:\{[^}]*\})? (\S+)$/m', $body, $matches) === 1
            ? $matches[1]
            : null;
    }

    /**
     * Off unless an operator turns it on: a Composer registry is routinely
     * published to the internet, so an on-by-default endpoint would widen every
     * installation's exposure on upgrade.
     */
    public function test_it_is_off_by_default(): void
    {
        $this->assertFalse((bool) config('registry.metrics.enabled'));

        // A 404 rather than a 403: there is nothing here, and telling a
        // stranger that there is something they may not have is worse.
        $this->get('/metrics')->assertNotFound();
    }

    public function test_it_serves_the_prometheus_exposition_format(): void
    {
        $this->scrape()
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')
            // Laravel spells its own `private` alongside; what matters is that
            // a point-in-time reading is never replayed from a cache.
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('# HELP package_pipeline_up', escape: false)
            ->assertSee('# TYPE package_pipeline_up gauge', escape: false);
    }

    public function test_a_token_is_required_when_one_is_configured(): void
    {
        config(['registry.metrics.token' => 'scrape-me']);

        $this->scrape()->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer wrong')->scrape()->assertUnauthorized();

        $this->withHeader('Authorization', 'Bearer scrape-me')->scrape()->assertOk();
    }

    /**
     * The token is optional, so an enabled endpoint may be anonymous — and a
     * scrape with the exposition cache off is a dozen aggregate queries. The
     * ceiling is far past what any Prometheus deployment scrapes and is what
     * stops a flood costing that dozen per request.
     */
    public function test_scrapes_are_throttled(): void
    {
        for ($scrape = 1; $scrape <= 400; $scrape++) {
            $response = $this->scrape();

            if ($response->getStatusCode() === 429) {
                $response->assertHeader('Retry-After');

                return;
            }
        }

        $this->fail('The metrics endpoint answered an unbounded number of scrapes.');
    }

    public function test_it_reports_registry_totals(): void
    {
        $package = Package::factory()->create(['total_downloads' => 41]);
        Package::factory()->create(['total_downloads' => 1]);

        $package->versions()->create([
            'version' => '1.0.0',
            'reference' => str_repeat('a', 40),
            'is_dev' => false,
            'metadata' => ['name' => $package->name, 'version' => '1.0.0'],
        ]);

        $body = $this->scrape()->assertOk()->getContent();

        $this->assertSame('2', $this->sample($body, 'packages'));
        $this->assertSame('1', $this->sample($body, 'versions'));
        // Summed from the denormalized counters, never counted over the
        // downloads table — which is the fastest-growing one in the schema.
        $this->assertSame('42', $this->sample($body, 'downloads_total'));

        // A counter and not a gauge: it only goes up, and rate() is the whole
        // reason anybody graphs it.
        $this->assertStringContainsString('# TYPE package_pipeline_downloads_total counter', $body);
    }

    public function test_it_reports_sync_health(): void
    {
        Package::factory()->create(['last_synced_at' => now()->subMinutes(10), 'sync_error' => 'GitHub timed out.']);
        Package::factory()->create(['last_synced_at' => now()->subDays(3)]);
        Package::factory()->create(['last_synced_at' => null]);

        // Published by artifact upload: no repository to sync from, so it is
        // not stale and must not put a floor under any of these.
        Package::factory()->create(['repository' => null, 'last_synced_at' => null]);

        $body = $this->scrape()->assertOk()->getContent();

        $this->assertSame('1', $this->sample($body, 'packages_failing'));
        $this->assertSame('1', $this->sample($body, 'packages_never_synced'));
        $this->assertSame('2', $this->sample($body, 'packages_stale'));

        // Roughly ten minutes since the most recent successful sync.
        $this->assertEqualsWithDelta(600, (int) $this->sample($body, 'last_sync_age_seconds'), 5);
    }

    public function test_the_sync_age_is_zero_on_a_registry_that_has_never_synced(): void
    {
        $body = $this->scrape()->assertOk()->getContent();

        $this->assertSame('0', $this->sample($body, 'last_sync_age_seconds'));
    }

    public function test_it_reports_queue_depth_on_the_database_driver(): void
    {
        config(['queue.default' => 'database']);

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinutes(4)->getTimestamp(),
            'created_at' => now()->subMinutes(4)->getTimestamp(),
        ]);

        $body = $this->scrape()->assertOk()->getContent();

        $this->assertSame('1', $this->sample($body, 'queue_pending_jobs'));
        $this->assertSame('0', $this->sample($body, 'queue_failed_jobs'));
        // Depth alone cannot tell a busy worker from a dead one; how long the
        // oldest job has waited can.
        $this->assertEqualsWithDelta(240, (int) $this->sample($body, 'queue_oldest_pending_seconds'), 5);
    }

    /**
     * On Redis or SQS the queue is somebody else's to report, and a fabricated
     * zero would silently satisfy an alert forever. Absence is a state
     * Prometheus expresses; a wrong number is not.
     */
    public function test_queue_depth_is_absent_on_a_driver_this_app_cannot_count(): void
    {
        config(['queue.default' => 'redis']);

        $body = $this->scrape()->assertOk()->getContent();

        $this->assertNull($this->sample($body, 'queue_pending_jobs'));
        $this->assertNull($this->sample($body, 'queue_oldest_pending_seconds'));
    }

    public function test_it_reports_failing_outgoing_webhooks(): void
    {
        OutgoingWebhook::factory()->create();
        OutgoingWebhook::factory()->create(['consecutive_failures' => 6]);
        OutgoingWebhook::factory()->inactive()->create(['consecutive_failures' => 9]);

        $body = $this->scrape()->assertOk()->getContent();

        $this->assertSame('1', $this->sample($body, 'outgoing_webhooks_failing'));
    }

    /**
     * An installation with no upstreams is every installation until an operator
     * adds one, and a row of zeroes about a feature nobody uses is noise.
     */
    public function test_mirror_metrics_are_absent_until_something_mirrors(): void
    {
        $body = $this->scrape()->assertOk()->getContent();

        $this->assertNull($this->sample($body, 'mirror_archives'));
    }

    public function test_it_reports_what_the_mirror_holds(): void
    {
        $upstream = Upstream::factory()->create();

        MirroredPackage::factory()->create(['upstream_id' => $upstream->getKey()]);

        MirroredArchive::factory()->create([
            'upstream_id' => $upstream->getKey(),
            'reference' => str_repeat('a', 40),
            'size' => 1024,
        ]);
        MirroredArchive::factory()->create([
            'upstream_id' => $upstream->getKey(),
            'reference' => str_repeat('b', 40),
            'size' => 2048,
        ]);

        $body = $this->scrape()->assertOk()->getContent();

        $this->assertSame('1', $this->sample($body, 'mirror_documents'));
        $this->assertSame('2', $this->sample($body, 'mirror_archives'));
        // The only one of these that maps to a bill.
        $this->assertSame('3072', $this->sample($body, 'mirror_archive_bytes'));
    }

    /**
     * Two Prometheus replicas scraping one instance is the ordinary
     * deployment, and the second should cost a cache read rather than the
     * whole collection again.
     */
    public function test_a_scrape_is_cached_for_the_configured_window(): void
    {
        config(['registry.metrics.cache_seconds' => 60]);

        Package::factory()->create();

        $this->assertSame('1', $this->sample($this->scrape()->getContent(), 'packages'));

        Package::factory()->create();

        $this->assertSame('1', $this->sample($this->scrape()->getContent(), 'packages'));

        cache()->forget('metrics:exposition');

        $this->assertSame('2', $this->sample($this->scrape()->getContent(), 'packages'));
    }

    /**
     * A gauge per package would make this endpoint's cardinality a function of
     * how big the registry is, and would publish every private package's name
     * to whoever can scrape.
     */
    public function test_no_package_names_are_exposed(): void
    {
        Package::factory()->create(['name' => 'acme/very-secret-thing']);

        $this->scrape()->assertOk()->assertDontSee('acme/very-secret-thing');
    }
}
