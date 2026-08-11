<?php

namespace Tests\Feature;

use App\Jobs\SyncPackageJob;
use App\Models\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The console syncs inline, in its own process, where the queue's overlap
 * middleware cannot reach it. Left alone, `package:rebuild` run during a
 * webhook-triggered batch put two writers on one package's versions — and the
 * one that prunes last decides what survives.
 */
class ConsoleSyncGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function makePackage(): Package
    {
        return Package::factory()->unreleased()->create([
            'name' => 'acme/widgets',
            'repository' => 'https://github.com/acme/widgets',
            'token' => 'ghp_secret',
        ]);
    }

    /**
     * A package with a sync batch still working through its versions.
     */
    private function importing(): Package
    {
        $package = $this->makePackage();

        DB::table('job_batches')->insert([
            'id' => $id = (string) Str::orderedUuid(),
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

        $package->forceFill(['sync_batch_id' => $id])->save();

        return $package;
    }

    public function test_the_sync_command_stands_aside_for_a_batch_still_importing(): void
    {
        $package = $this->importing();

        $this->artisan('packages:sync', ['name' => 'acme/widgets'])
            ->expectsOutputToContain('a sync is already running for it')
            ->assertFailed();

        // Nothing was read and nothing was written: the running batch owns the
        // package's versions until it is done.
        $this->assertSame(0, $package->versions()->count());
        Http::assertNothingSent();
    }

    public function test_the_rebuild_command_stands_aside_for_a_batch_still_importing(): void
    {
        $package = $this->importing();

        $this->artisan('package:rebuild', ['name' => 'acme/widgets'])
            ->expectsOutputToContain('not rebuilt: a sync is already running for it')
            ->assertFailed();

        $this->assertSame(0, $package->versions()->count());
        Http::assertNothingSent();
    }

    /**
     * A debounced webhook sync sitting on the queue holds the same uniqueness
     * lock, and it will start the moment a worker picks it up.
     */
    public function test_the_rebuild_command_stands_aside_for_a_queued_sync(): void
    {
        Queue::fake();

        $this->makePackage();

        $this->assertTrue(SyncPackageJob::dispatchUnlessPending(Package::query()->sole()));

        $this->artisan('package:rebuild', ['name' => 'acme/widgets'])
            ->expectsOutputToContain('a sync is already queued for it')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_an_inline_sync_holds_the_lock_only_while_it_runs(): void
    {
        Queue::fake();

        $package = $this->makePackage();

        $this->artisan('packages:sync', ['name' => 'acme/widgets'])->assertSuccessful();

        $this->assertSame(1, $package->versions()->count());

        // Released, or the package would answer "already queued" to every sync
        // until the uniqueness lock expired an hour later.
        $this->assertTrue(SyncPackageJob::dispatchUnlessPending($package));
    }

    public function test_the_lock_is_released_when_the_inline_sync_fails(): void
    {
        Queue::fake();

        Http::fake(['api.github.com/repos/acme/broken/tags*' => Http::response([], 500)]);

        $package = Package::factory()->unreleased()->create([
            'name' => 'acme/broken',
            'repository' => 'https://github.com/acme/broken',
        ]);

        $this->artisan('packages:sync', ['name' => 'acme/broken'])->assertFailed();

        $this->assertTrue(SyncPackageJob::dispatchUnlessPending($package));
    }

    /**
     * The queued path is the one every other caller uses, and it carries its
     * own uniqueness rules; the guard must not add a second refusal on top.
     */
    public function test_the_queued_flag_still_dispatches_while_a_batch_is_importing(): void
    {
        Queue::fake();

        $this->importing();

        $this->artisan('packages:sync', ['name' => 'acme/widgets', '--queue' => true])
            ->expectsOutputToContain('queued')
            ->assertSuccessful();

        Queue::assertPushed(SyncPackageJob::class);
    }
}
