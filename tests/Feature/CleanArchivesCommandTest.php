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

        // Written just now, the orphan is inside the grace window and would be
        // spared; age it into the past so these cases test the deletion.
        $this->age('packages/acme/widgets/orphan.zip');

        PackageVersion::factory()
            ->for(Package::factory()->create(['name' => 'acme/widgets']))
            ->create([
                'archive_path' => 'packages/acme/widgets/kept.zip',
                'shasum' => sha1('zip-bytes'),
            ]);

        return $disk;
    }

    /**
     * Backdate a file on the faked disk past the command's grace window.
     */
    private function age(string $path): void
    {
        touch(Storage::disk(config('filesystems.dists'))->path($path), now()->subDay()->getTimestamp());
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

    /**
     * The import writes the archive inside the transaction that saves the row
     * pointing at it, so a clean run landing in that window sees a file no
     * committed row references. Deleting it would commit a version whose
     * archive is already gone, and dist would 404 for it until the next sync.
     */
    public function test_clean_spares_a_file_that_may_still_be_mid_import(): void
    {
        $disk = $this->disk();
        $disk->put('packages/acme/widgets/01931f00-committing.zip', 'zip-bytes');

        $this->artisan('archives:clean')
            ->doesntExpectOutputToContain('01931f00-committing.zip')
            ->expectsOutputToContain('left alone')
            ->assertSuccessful();

        $disk->assertExists('packages/acme/widgets/01931f00-committing.zip');

        // Only the fresh file is spared; the settled orphan still goes.
        $disk->assertMissing('packages/acme/widgets/orphan.zip');
    }

    public function test_a_dry_run_leaves_recent_files_out_of_the_preview(): void
    {
        $disk = $this->disk();
        $disk->delete('packages/acme/widgets/orphan.zip');
        $disk->put('packages/acme/widgets/01931f00-committing.zip', 'zip-bytes');

        $this->artisan('archives:clean', ['--dry-run' => true])
            ->expectsOutputToContain('too recently written')
            ->assertSuccessful();

        $disk->assertExists('packages/acme/widgets/01931f00-committing.zip');
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
