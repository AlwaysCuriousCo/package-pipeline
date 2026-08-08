<?php

namespace Tests\Feature;

use App\Filament\Resources\Packages\Pages\ViewPackage;
use App\Jobs\SyncPackageJob;
use App\Models\Package;
use App\Models\User;
use App\Services\RebuildPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Rebuild is a forced sync: every version re-imported from the source,
 * trusting nothing stored, idempotent by design.
 */
class PackageRebuildTest extends TestCase
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

    private function makeSyncedPackage(): Package
    {
        $package = Package::factory()->unreleased()->create([
            'name' => 'acme/widgets',
            'repository' => 'https://github.com/acme/widgets',
            'token' => 'ghp_secret',
        ]);

        app(RebuildPackage::class)->rebuild($package);

        return $package->refresh();
    }

    public function test_rebuild_restores_a_mutated_row_and_a_missing_archive(): void
    {
        $package = $this->makeSyncedPackage();

        $version = $package->versions()->sole();

        // The two corruptions a sync would never notice: the row still says
        // stored-and-unmoved, so unchanged() skips it.
        $version->forceFill(['metadata' => ['name' => 'acme/widgets', 'require' => ['php' => 'corrupted']]])->save();
        Storage::disk(config('filesystems.dists'))->delete($version->archive_path);

        app(RebuildPackage::class)->rebuild($package);

        $version->refresh();

        $this->assertArrayNotHasKey('require', $version->metadata);
        $this->assertSame('Widgets for Acme.', $version->metadata['description']);
        $this->assertSame(sha1('zip-bytes'), $version->shasum);
        Storage::disk(config('filesystems.dists'))->assertExists($version->archive_path);
    }

    public function test_rebuild_prunes_rows_the_source_no_longer_publishes(): void
    {
        $package = $this->makeSyncedPackage();

        $package->versions()->create([
            'version' => '9.9.9',
            'reference' => str_repeat('f', 40),
            'is_dev' => false,
            'metadata' => ['name' => 'acme/widgets', 'version' => '9.9.9'],
        ]);

        app(RebuildPackage::class)->rebuild($package);

        $this->assertSame(['1.0.0'], $package->versions()->pluck('version')->all());
    }

    public function test_rebuild_is_idempotent(): void
    {
        $package = $this->makeSyncedPackage();

        $first = $package->versions()->get(['version', 'reference', 'shasum', 'metadata'])->toArray();

        app(RebuildPackage::class)->rebuild($package);

        $second = $package->versions()->get(['version', 'reference', 'shasum', 'metadata'])->toArray();

        $this->assertSame($first, $second);
        $this->assertSame('1.0.0', $package->fresh()->latest_version);
    }

    public function test_the_command_rebuilds_one_or_all_packages(): void
    {
        $package = $this->makeSyncedPackage();

        // An uploaded artifact has no source; the sweep must skip it, not die.
        Package::factory()->create(['name' => 'acme/artifact', 'repository' => null]);

        $this->artisan('package:rebuild', ['name' => 'acme/widgets'])->assertSuccessful();
        $this->artisan('package:rebuild')->assertSuccessful();

        $this->assertSame(1, $package->versions()->count());

        $this->artisan('package:rebuild', ['name' => 'acme/artifact'])->assertFailed();
    }

    public function test_the_panel_action_queues_a_forced_sync(): void
    {
        $package = $this->makeSyncedPackage();

        Queue::fake();

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(ViewPackage::class, ['record' => $package->getKey()])
            ->callAction('rebuild')
            ->assertNotified();

        Queue::assertPushed(SyncPackageJob::class, fn (SyncPackageJob $job): bool => $job->force);
    }
}
