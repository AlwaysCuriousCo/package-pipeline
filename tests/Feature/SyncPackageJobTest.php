<?php

namespace Tests\Feature;

use App\Filament\Resources\Packages\Pages\ListPackages;
use App\Jobs\SyncPackageJob;
use App\Models\Package;
use App\Models\User;
use App\Services\PackageSynchronizer;
use Filament\Actions\Testing\TestAction;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    public function test_the_job_syncs_the_package(): void
    {
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
        ]);

        $package = $this->makePackage();

        (new SyncPackageJob($package))->handle(app(PackageSynchronizer::class));

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

        $this->actingAs(User::factory()->create());

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

    public function test_the_command_still_syncs_inline_without_the_queue_flag(): void
    {
        Queue::fake();
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

        $this->makePackage();

        $this->artisan('packages:sync')->assertFailed();

        Queue::assertNothingPushed();
    }
}
