<?php

namespace Tests\Feature;

use App\Exceptions\RateLimited;
use App\Jobs\DiscoverVersions;
use App\Jobs\ImportVersion;
use App\Models\Package;
use App\Services\PackageSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Being throttled, told apart from being refused.
 *
 * A rebuild of a large repository costs three API calls per ref and is exactly
 * what trips a provider's limits. Before this, every one of them arrived as
 * the same RequestException an expired token produces: retried blind against
 * the jobs' backoffs — seconds and minutes, against a budget that resets on
 * the hour — and recorded in `sync_error` in a way that reads like a
 * credential problem, sending an admin to rotate a working token.
 */
class ProviderRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.dists'));

        // Delays are asserted to the second below.
        $this->freezeTime();

        Http::preventStrayRequests();
    }

    private function makePackage(): Package
    {
        return Package::factory()->unreleased()->create([
            'name' => 'acme/widgets-placeholder',
            'repository' => 'https://github.com/acme/widgets',
            'token' => 'ghp_secret',
        ]);
    }

    /**
     * GitHub's primary budget, spent: 403 with the reset it will refill at.
     */
    private function fakeExhaustedBudget(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response(['message' => 'API rate limit exceeded'], 403, [
                'X-RateLimit-Limit' => '5000',
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset' => (string) now()->addMinutes(15)->getTimestamp(),
            ]),
        ]);
    }

    public function test_a_spent_budget_reports_when_the_sync_will_resume(): void
    {
        $this->fakeExhaustedBudget();

        $package = $this->makePackage();

        try {
            app(PackageSynchronizer::class)->sync($package);
            $this->fail('Expected the sync to report a rate limit.');
        } catch (RateLimited $limited) {
            $this->assertSame(now()->addMinutes(15)->getTimestamp(), $limited->retryAt->getTimestamp());
        }

        $error = (string) $package->refresh()->sync_error;

        $this->assertStringContainsString('GitHub rate limited', $error);
        $this->assertStringContainsString('will retry at', $error);
    }

    /**
     * GitHub answers a secondary limit with a 403 too, and says how long to
     * stay away rather than when the budget resets.
     */
    public function test_a_secondary_limit_is_honoured_by_its_retry_after(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response(
                ['message' => 'You have exceeded a secondary rate limit.'],
                403,
                ['Retry-After' => '90', 'X-RateLimit-Remaining' => '4300'],
            ),
        ]);

        try {
            app(PackageSynchronizer::class)->sync($this->makePackage());
            $this->fail('Expected the sync to report a rate limit.');
        } catch (RateLimited $limited) {
            $this->assertSame(now()->addSeconds(90)->getTimestamp(), $limited->retryAt->getTimestamp());
        }
    }

    /**
     * The distinction the ordering in RateLimited::from() exists for: GitHub
     * attaches its rate-limit headers to a permission failure as well, so a
     * reset time is no evidence of throttling on its own.
     */
    public function test_a_forbidden_response_with_budget_left_stays_an_ordinary_failure(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response(['message' => 'Bad credentials'], 403, [
                'X-RateLimit-Remaining' => '4999',
                'X-RateLimit-Reset' => (string) now()->addMinutes(30)->getTimestamp(),
            ]),
        ]);

        $this->expectException(RequestException::class);

        app(PackageSynchronizer::class)->sync($this->makePackage());
    }

    public function test_discovery_waits_for_the_reset_instead_of_retrying_into_it(): void
    {
        $this->fakeExhaustedBudget();

        $package = $this->makePackage();

        $job = (new DiscoverVersions($package))->withFakeQueueInteractions();

        $job->handle(app(PackageSynchronizer::class));

        // Released until the reset, rather than thrown — a thrown attempt
        // would come back in a minute to be refused for another fourteen.
        $job->assertReleased(15 * 60);
        $job->assertNotFailed();

        $this->assertStringContainsString('will retry at', (string) $package->refresh()->sync_error);
    }

    public function test_an_import_waits_for_the_reset_too(): void
    {
        $this->fakeExhaustedBudget();

        $package = $this->makePackage();

        $job = (new ImportVersion($package, '1.0.0', str_repeat('a', 40), false))
            ->withFakeQueueInteractions();

        $job->handle(app(PackageSynchronizer::class));

        $job->assertReleased(15 * 60);

        $this->assertStringContainsString('GitHub rate limited', (string) $package->refresh()->sync_error);
        $this->assertSame(0, $package->versions()->count());
    }

    public function test_gitlab_throttling_is_recognised_by_its_own_headers(): void
    {
        Http::fake([
            'gitlab.com/api/v4/*' => Http::response(['message' => 'Too many requests'], 429, [
                'RateLimit-Remaining' => '0',
                'RateLimit-Reset' => (string) now()->addMinutes(2)->getTimestamp(),
            ]),
        ]);

        $package = Package::factory()->unreleased()->create([
            'name' => 'group/widgets',
            'repository' => 'https://gitlab.com/group/widgets',
            'token' => 'glpat-secret',
        ]);

        try {
            app(PackageSynchronizer::class)->sync($package);
            $this->fail('Expected the sync to report a rate limit.');
        } catch (RateLimited $limited) {
            $this->assertSame(now()->addMinutes(2)->getTimestamp(), $limited->retryAt->getTimestamp());
            $this->assertStringContainsString('GitLab rate limited', $limited->getMessage());
        }
    }

    /**
     * A reset already in the past — a provider's clock, or a stale header —
     * would otherwise release the job straight back into the limit.
     */
    public function test_a_reset_in_the_past_still_buys_a_pause(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([], 403, [
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset' => (string) now()->subMinutes(5)->getTimestamp(),
            ]),
        ]);

        $job = (new DiscoverVersions($this->makePackage()))->withFakeQueueInteractions();

        $job->handle(app(PackageSynchronizer::class));

        $job->assertReleased(1);
    }
}
