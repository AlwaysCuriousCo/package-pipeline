<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PackageVersion;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanArchivesCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A referenced archive and an orphan side by side on the dist disk.
     */
    private function disk(): Filesystem
    {
        Storage::fake(config('filesystems.dists'));

        $disk = Storage::disk(config('filesystems.dists'));
        $disk->put('packages/acme/widgets/kept.zip', 'zip-bytes');
        $disk->put('packages/acme/widgets/orphan.zip', 'stale-bytes');

        PackageVersion::factory()
            ->for(Package::factory()->create(['name' => 'acme/widgets']))
            ->create([
                'archive_path' => 'packages/acme/widgets/kept.zip',
                'shasum' => sha1('zip-bytes'),
            ]);

        return $disk;
    }

    public function test_clean_deletes_only_unreferenced_archives(): void
    {
        $disk = $this->disk();

        $this->artisan('archives:clean')
            ->expectsOutputToContain('packages/acme/widgets/orphan.zip')
            ->assertSuccessful();

        $disk->assertExists('packages/acme/widgets/kept.zip');
        $disk->assertMissing('packages/acme/widgets/orphan.zip');
    }

    public function test_a_dry_run_reports_orphans_without_deleting_them(): void
    {
        $disk = $this->disk();

        $this->artisan('archives:clean', ['--dry-run' => true])
            ->expectsOutputToContain('packages/acme/widgets/orphan.zip')
            ->assertSuccessful();

        $disk->assertExists('packages/acme/widgets/kept.zip');
        $disk->assertExists('packages/acme/widgets/orphan.zip');
    }

    public function test_clean_reports_when_nothing_is_orphaned(): void
    {
        $disk = $this->disk();
        $disk->delete('packages/acme/widgets/orphan.zip');

        $this->artisan('archives:clean')
            ->expectsOutputToContain('nothing to clean')
            ->assertSuccessful();

        $disk->assertExists('packages/acme/widgets/kept.zip');
    }
}
