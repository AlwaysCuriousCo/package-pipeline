<?php

namespace Tests\Feature;

use App\Filament\Resources\Packages\Widgets\PackageSyncProgress;
use App\Jobs\DiscoverVersions;
use App\Jobs\PackageSyncBatch;
use App\Jobs\SyncPackageJob;
use App\Models\Package;
use App\Models\User;
use App\Notifications\PackageSyncFailed;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * The sync as a job batch: discovery fanning out one import job per ref,
 * failures costing one version rather than the sync, and the panel able to
 * watch it all happen.
 */
class PackageSyncBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Syncing stores an archive per version on the dist disk.
        Storage::fake(config('filesystems.dists'));
    }

    /**
     * Fake the endpoints a sync reads. Overrides are registered ahead of the
     * defaults because the first matching stub wins.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function fakeGitHub(array $overrides = []): void
    {
        Http::fake($overrides + [
            'api.github.com/repos/acme/widgets/tags*' => Http::response([
                ['name' => 'v1.0.0', 'commit' => ['sha' => str_repeat('a', 40)]],
                ['name' => 'v1.1.0', 'commit' => ['sha' => str_repeat('b', 40)]],
            ]),
            'api.github.com/repos/acme/widgets/branches*' => Http::response([
                ['name' => 'main', 'commit' => ['sha' => str_repeat('d', 40)]],
                ['name' => '2.x', 'commit' => ['sha' => str_repeat('e', 40)]],
            ]),
            'api.github.com/repos/acme/widgets/contents/composer.json*' => Http::response([
                'name' => 'acme/widgets',
                'description' => 'Widgets for Acme.',
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

    private function makePackage(): Package
    {
        return Package::factory()->unreleased()->create([
            'name' => 'acme/widgets-placeholder',
            'repository' => 'https://github.com/acme/widgets',
        ]);
    }

    /**
     * A batch row as the repository would store it, so tests can stage a sync
     * in any state without racing a real worker.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function storedBatch(array $attributes = []): string
    {
        $id = (string) Str::orderedUuid();

        DB::table('job_batches')->insert($attributes + [
            'id' => $id,
            'name' => 'Sync acme/widgets',
            'total_jobs' => 5,
            'pending_jobs' => 3,
            'failed_jobs' => 0,
            'failed_job_ids' => '[]',
            'options' => serialize([]),
            'cancelled_at' => null,
            'created_at' => now()->getTimestamp(),
            'finished_at' => null,
        ]);

        return $id;
    }

    public function test_the_batch_imports_every_version_and_finalizes_the_package(): void
    {
        $this->fakeGitHub();

        $package = $this->makePackage();

        PackageSyncBatch::dispatchFor($package);

        $package->refresh();

        $this->assertSame('acme/widgets', $package->name);
        // Tags are stored under their normalized spelling: v1.1.0 → 1.1.0.
        $this->assertSame('1.1.0', $package->latest_version);
        $this->assertSame('Widgets for Acme.', $package->description);
        $this->assertNotNull($package->last_synced_at);
        $this->assertNull($package->sync_error);
        $this->assertSame(4, $package->versions()->count());

        // Every version got its archive, exactly as the inline sync stores it.
        $this->assertSame(0, $package->versions()->whereNull('archive_path')->count());

        // One discovery plus one import per ref, all accounted for.
        $batch = $package->syncBatch();

        $this->assertNotNull($batch);
        $this->assertTrue($batch->finished());
        $this->assertSame(5, $batch->totalJobs);
        $this->assertSame(0, $batch->failedJobs);
        $this->assertFalse($package->syncRunning());
    }

    public function test_a_second_batch_fans_out_no_import_for_unmoved_refs(): void
    {
        $this->fakeGitHub();

        $package = $this->makePackage();

        PackageSyncBatch::dispatchFor($package);
        PackageSyncBatch::dispatchFor($package->refresh());

        // Nothing moved, so the second batch is the discovery job alone.
        $this->assertSame(1, $package->refresh()->syncBatch()->totalJobs);
        $this->assertSame(4, $package->versions()->count());
    }

    /**
     * The reason the sync is a batch at all: with allowFailures(), a tag whose
     * archive cannot be fetched fails its own import job and nothing else.
     * Needs a real queue — on the sync driver an exception has no batch to be
     * isolated by.
     */
    public function test_a_failing_ref_costs_its_own_version_and_not_the_sync(): void
    {
        config(['queue.default' => 'database']);

        // v1.1.0's zipball comes back as an HTML error page every time.
        $this->fakeGitHub([
            'api.github.com/repos/acme/widgets/zipball/*' => fn ($request) => str_contains($request->url(), str_repeat('b', 40))
                ? Http::response('<html>maintenance</html>', 200, ['Content-Type' => 'text/html'])
                : Http::response('zip-bytes', 200, ['Content-Type' => 'application/zip']),
        ]);

        $package = $this->makePackage();

        PackageSyncBatch::dispatchFor($package);

        // First pass: discovery fans out, three imports land, the fourth is
        // released for retry. The batch cannot finish until the retries are
        // spent, so wind the clock past each backoff and let the worker at it.
        $this->artisan('queue:work', ['--stop-when-empty' => true])->assertSuccessful();

        foreach ([31, 121] as $seconds) {
            $this->travelTo(now()->addSeconds($seconds));
            $this->artisan('queue:work', ['--stop-when-empty' => true])->assertSuccessful();
        }

        $package->refresh();

        // Three of four versions made it; the broken one is simply missing.
        $this->assertSame(
            ['1.0.0', '2.x-dev', 'dev-main'],
            $package->versions()->pluck('version')->sort()->values()->all(),
        );

        // The package still counts as synced — and says what it is missing.
        $this->assertNotNull($package->last_synced_at);
        $this->assertSame('1.0.0', $package->latest_version);
        $this->assertSame('1 of 4 version imports failed; the next sync will retry them.', $package->sync_error);

        $this->assertSame(1, $package->syncBatch()->failedJobs);
        $this->assertFalse($package->syncRunning());
    }

    public function test_a_failed_discovery_cancels_the_batch_and_raises_the_alarm(): void
    {
        Notification::fake();

        User::factory()->superAdmin()->create();

        $package = $this->makePackage();

        // An empty real batch to hang the job on; failed() must cancel it so
        // the finalizer cannot stamp the package as freshly synced.
        $batch = Bus::batch([])->allowFailures()->dispatch();

        $job = (new DiscoverVersions($package))->withBatchId($batch->id);
        $job->failed(new RuntimeException('GitHub timed out.'));

        $this->assertSame('GitHub timed out.', $package->refresh()->sync_error);
        $this->assertNull($package->last_synced_at);
        $this->assertTrue(Bus::findBatch($batch->id)->cancelled());

        Notification::assertSentTo(
            User::query()->get(),
            PackageSyncFailed::class,
            fn (PackageSyncFailed $notification): bool => str_contains($notification->reason, 'GitHub timed out.'),
        );
    }

    public function test_a_sync_steps_aside_while_a_batch_is_still_importing(): void
    {
        Bus::fake();

        $package = $this->makePackage();
        $package->forceFill(['sync_batch_id' => $this->storedBatch()])->save();

        (new SyncPackageJob($package))->handle();

        // No new batch — the running one owns the package's versions — but the
        // sync is not dropped either: it comes back once the imports are done.
        Bus::assertNothingBatched();
        Bus::assertDispatched(
            SyncPackageJob::class,
            fn (SyncPackageJob $job): bool => $job->package->is($package) && $job->delay !== null,
        );
    }

    public function test_a_finished_batch_does_not_block_the_next_sync(): void
    {
        Bus::fake();

        $package = $this->makePackage();
        $package->forceFill(['sync_batch_id' => $this->storedBatch([
            'pending_jobs' => 0,
            'finished_at' => now()->getTimestamp(),
        ])])->save();

        (new SyncPackageJob($package))->handle();

        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->first() instanceof DiscoverVersions);
        Bus::assertNotDispatched(SyncPackageJob::class);
    }

    /**
     * A batch that finished with failures never gets a finished_at — only its
     * counts say every job has run. It must read as done, or the package could
     * never sync again.
     */
    public function test_a_batch_done_but_for_failures_does_not_block_the_next_sync(): void
    {
        Bus::fake();

        $package = $this->makePackage();
        $package->forceFill(['sync_batch_id' => $this->storedBatch([
            'pending_jobs' => 1,
            'failed_jobs' => 1,
        ])])->save();

        $this->assertFalse($package->syncRunning());

        (new SyncPackageJob($package))->handle();

        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->first() instanceof DiscoverVersions);
    }

    public function test_a_batch_lost_for_hours_does_not_wedge_the_package(): void
    {
        Bus::fake();

        $package = $this->makePackage();
        $package->forceFill(['sync_batch_id' => $this->storedBatch([
            'created_at' => now()->subHours(3)->getTimestamp(),
        ])])->save();

        (new SyncPackageJob($package))->handle();

        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->first() instanceof DiscoverVersions);
    }

    public function test_the_view_page_widget_shows_the_running_import(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $package = $this->makePackage();
        $package->forceFill(['sync_batch_id' => $this->storedBatch([
            'pending_jobs' => 3,
            'failed_jobs' => 1,
        ])])->save();

        // total 5 − pending 3 = 2 processed; minus the discovery job, the
        // panel reads "1 of 4 versions imported".
        Livewire::test(PackageSyncProgress::class, ['record' => $package])
            ->assertSee('Sync in progress')
            ->assertSee('1 of 4 versions imported')
            ->assertSee('1 failed');
    }

    public function test_the_widget_stays_out_of_the_way_when_nothing_is_running(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(PackageSyncProgress::class, ['record' => $this->makePackage()])
            ->assertDontSee('Sync in progress');
    }
}
