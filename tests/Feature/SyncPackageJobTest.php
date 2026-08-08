<?php

namespace Tests\Feature;

use App\Filament\Resources\Packages\Pages\ListPackages;
use App\Jobs\SyncPackageJob;
use App\Models\Package;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class SyncPackageJobTest extends TestCase
{
    use RefreshDatabase;

    private function makePackage(): Package
    {
        return Package::factory()->unreleased()->create([
            'name' => 'acme/widgets-placeholder',
            'repository' => 'https://github.com/acme/widgets',
        ]);
    }

    /**
     * A repository with one tag and no branches, which is enough for a sync to
     * have visibly happened.
     */
    private function fakeGitHub(): void
    {
        // A successful sync stores each version's archive on the dist disk.
        Storage::fake(config('filesystems.dists'));

        Http::fake([
            'api.github.com/repos/acme/widgets/tags*' => Http::response([
                ['name' => 'v1.0.0', 'commit' => ['sha' => str_repeat('a', 40)]],
            ]),
            'api.github.com/repos/acme/widgets/branches*' => Http::response([]),
            'api.github.com/repos/acme/widgets/contents/composer.json*' => Http::response([
                'name' => 'acme/widgets',
                'type' => 'library',
            ]),
            'api.github.com/repos/acme/widgets/commits/*' => Http::response([
                'commit' => ['committer' => ['date' => '2026-02-01T12:00:00Z']],
            ]),
            'api.github.com/repos/acme/widgets/zipball/*' => fn () => Http::response('zip-bytes', 200, [
                'Content-Type' => 'application/zip',
            ]),
        ]);
    }

    public function test_the_job_syncs_the_package(): void
    {
        $this->fakeGitHub();

        $package = $this->makePackage();

        $this->app->call([new SyncPackageJob($package), 'handle']);

        $this->assertSame('acme/widgets', $package->refresh()->name);
        $this->assertSame('v1.0.0', $package->latest_version);
        $this->assertSame(1, $package->versions()->count());
    }

    public function test_the_job_is_unique_per_package_until_it_starts(): void
    {
        $package = $this->makePackage();
        $other = Package::factory()->create();

        $job = new SyncPackageJob($package);

        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertSame((string) $package->getKey(), $job->uniqueId());
        $this->assertNotSame($job->uniqueId(), (new SyncPackageJob($other))->uniqueId());
    }

    public function test_the_job_will_not_overlap_with_another_sync_of_the_same_package(): void
    {
        $package = $this->makePackage();
        $other = Package::factory()->create();

        $middleware = (new SyncPackageJob($package))->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);

        // Two jobs for one package share a lock; two packages do not. The same
        // job goes into every call, so only the middleware's key varies.
        $job = new SyncPackageJob($package);

        $this->assertSame(
            $middleware[0]->getLockKey($job),
            (new SyncPackageJob($package))->middleware()[0]->getLockKey($job),
        );
        $this->assertNotSame(
            $middleware[0]->getLockKey($job),
            (new SyncPackageJob($other))->middleware()[0]->getLockKey($job),
        );
    }

    public function test_the_job_survives_the_round_trip_through_the_queue(): void
    {
        $package = $this->makePackage();

        // Queue::fake() never serializes, so the payload a real worker reads
        // back is only exercised here.
        $restored = unserialize(serialize(new SyncPackageJob($package)));

        $this->assertTrue($restored->package->is($package));
        $this->assertSame((string) $package->getKey(), $restored->uniqueId());
    }

    public function test_a_permanently_failed_job_records_the_reason_on_the_package(): void
    {
        $package = $this->makePackage();

        (new SyncPackageJob($package))->failed(new RuntimeException('GitHub timed out.'));

        $this->assertSame('GitHub timed out.', $package->refresh()->sync_error);
    }

    public function test_a_failed_job_without_an_exception_still_records_a_reason(): void
    {
        $package = $this->makePackage();

        (new SyncPackageJob($package))->failed(null);

        $this->assertNotNull($package->refresh()->sync_error);
    }

    public function test_the_panel_action_queues_the_sync_instead_of_running_it(): void
    {
        Queue::fake();
        Http::fake();

        $this->actingAs(User::factory()->superAdmin()->create());

        $package = $this->makePackage();

        Livewire::test(ListPackages::class)
            ->callAction(TestAction::make('sync')->table($package))
            ->assertNotified();

        Queue::assertPushed(
            SyncPackageJob::class,
            fn (SyncPackageJob $job): bool => $job->package->is($package),
        );

        // The request itself must not touch GitHub any more.
        Http::assertNothingSent();
    }

    /**
     * The job is unique per package until it starts, and a push webhook holds
     * that lock for at least the debounce delay. A manual sync landing in the
     * window is dropped — which is fine, as long as nobody is told otherwise.
     */
    public function test_the_panel_says_so_when_a_sync_is_already_pending(): void
    {
        Queue::fake();
        Http::fake();

        $this->actingAs(User::factory()->superAdmin()->create());

        $package = $this->makePackage();

        // A webhook got there first: the uniqueness lock is now held.
        SyncPackageJob::debounced($package);

        Livewire::test(ListPackages::class)
            ->callAction(TestAction::make('sync')->table($package))
            ->assertNotified('Sync already queued');

        // The debounced sync is the only one on the queue.
        Queue::assertPushed(SyncPackageJob::class, 1);
    }

    public function test_dispatch_unless_pending_reports_the_dropped_duplicate(): void
    {
        Queue::fake();

        $package = $this->makePackage();

        $this->assertTrue(SyncPackageJob::dispatchUnlessPending($package));
        $this->assertFalse(SyncPackageJob::dispatchUnlessPending($package));

        Queue::assertPushed(SyncPackageJob::class, 1);
    }

    /**
     * The lock is taken before the dispatch, so a dispatch that throws must
     * give it back — leaked, it would answer every sync for the next hour
     * with "already queued" while nothing is on the queue at all.
     */
    public function test_a_failed_dispatch_releases_the_uniqueness_lock(): void
    {
        Queue::fake();

        $package = $this->makePackage();

        $this->mock(Dispatcher::class)
            ->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('The queue connection refused.'));

        try {
            SyncPackageJob::dispatchUnlessPending($package);
            $this->fail('The dispatch failure should have surfaced.');
        } catch (RuntimeException) {
            // The caller hears about it rather than being told "queued".
        }

        // With the real dispatcher back, the very next attempt goes through —
        // which it only can if the failed one released its lock. Mocking the
        // contract dropped its alias, so the concrete binding restores it.
        $this->app->instance(Dispatcher::class, $this->app->make(BusDispatcher::class));

        $this->assertTrue(SyncPackageJob::dispatchUnlessPending($package));

        Queue::assertPushed(SyncPackageJob::class, 1);
    }

    public function test_the_command_dispatches_instead_of_syncing_when_queued(): void
    {
        Queue::fake();
        Http::fake();

        $package = $this->makePackage();

        $this->artisan('packages:sync', ['--queue' => true])
            ->expectsOutputToContain('queued')
            ->assertSuccessful();

        Queue::assertPushed(
            SyncPackageJob::class,
            fn (SyncPackageJob $job): bool => $job->package->is($package),
        );

        Http::assertNothingSent();
    }

    /**
     * The end-to-end path, with no Queue::fake() anywhere: the command writes a
     * real row to the `jobs` table and a real worker picks up the whole
     * cascade — dispatcher, discovery, one import per ref, finalization.
     * Everything above this proves only that dispatch() was called.
     */
    public function test_a_queued_sync_is_picked_up_and_run_by_a_worker(): void
    {
        // The suite runs on the sync driver, which would defeat the point.
        config(['queue.default' => 'database']);

        $this->fakeGitHub();

        $package = $this->makePackage();

        $this->artisan('packages:sync', ['--queue' => true])->assertSuccessful();

        // Nothing has run yet: the work is parked in the database, and the
        // batch does not exist until the dispatcher job runs.
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseCount('job_batches', 0);

        $payload = json_decode((string) DB::table('jobs')->value('payload'), true);

        $this->assertSame(SyncPackageJob::class, $payload['displayName']);
        $this->assertSame(3, $payload['maxTries']);
        $this->assertSame(60, $payload['timeout']);
        $this->assertNull($package->refresh()->last_synced_at);

        $this->artisan('queue:work', ['--stop-when-empty' => true])->assertSuccessful();

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSame('acme/widgets', $package->refresh()->name);
        $this->assertSame('v1.0.0', $package->latest_version);
        $this->assertNotNull($package->last_synced_at);
        $this->assertNull($package->sync_error);

        // The batch ran to completion and the package knows which one it was.
        $this->assertNotNull($package->syncBatch());
        $this->assertTrue($package->syncBatch()->finished());
        $this->assertFalse($package->syncRunning());
    }

    public function test_a_worker_records_a_failed_sync_rather_than_losing_it(): void
    {
        config(['queue.default' => 'database']);

        Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

        $package = $this->makePackage();

        $this->artisan('packages:sync', ['--queue' => true])->assertSuccessful();

        // The dispatcher job never touches GitHub, so it succeeds and leaves
        // the discovery job on the queue.
        $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

        // The worker exits 0 whether or not the job it ran threw; the outcome
        // is recorded on the job, so that is where to look.
        $this->artisan('queue:work', ['--once' => true])->assertSuccessful();

        // One attempt of three: the discovery goes back to the queue, not to
        // the floor.
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSame(1, DB::table('jobs')->value('attempts'));
        $this->assertStringContainsString('Bad credentials', (string) $package->refresh()->sync_error);
    }

    public function test_the_command_still_syncs_inline_without_the_queue_flag(): void
    {
        Queue::fake();
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

        $this->makePackage();

        $this->artisan('packages:sync')->assertFailed();

        Queue::assertNothingPushed();
    }
}
