<?php

namespace Tests\Feature;

use App\Models\MirroredArchive;
use App\Models\MirroredPackage;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Upstream;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Retention for the mirror cache, and its relationship with the two commands
 * that reconcile *published* archives against the same disk.
 */
class MirrorPruneTest extends TestCase
{
    use RefreshDatabase;

    private function disk(): Filesystem
    {
        Storage::fake(config('filesystems.dists'));

        return Storage::disk(config('filesystems.dists'));
    }

    /**
     * A mirrored archive with a file behind it, last served whenever asked.
     */
    private function mirroredArchive(Filesystem $disk, string $name, string $reference, ?string $usedAt = null): MirroredArchive
    {
        $upstream = Upstream::factory()->create();

        $path = "mirror/{$upstream->getKey()}/{$name}/{$reference}.zip";

        $disk->put($path, 'mirrored-zip-bytes');

        return MirroredArchive::factory()->create([
            'upstream_id' => $upstream->getKey(),
            'name' => $name,
            'reference' => $reference,
            'path' => $path,
            'size' => 18,
            'used_at' => $usedAt ?? now(),
        ]);
    }

    public function test_pruning_removes_what_nothing_has_asked_for_and_keeps_what_it_has(): void
    {
        $disk = $this->disk();

        $cold = $this->mirroredArchive($disk, 'symfony/console', str_repeat('a', 40), now()->subDays(90));
        $warm = $this->mirroredArchive($disk, 'symfony/finder', str_repeat('b', 40));

        $staleDocument = MirroredPackage::factory()->create(['used_at' => now()->subDays(90)]);
        $usedDocument = MirroredPackage::factory()->create(['name' => 'vendor/warm', 'used_at' => now()]);

        $this->artisan('mirror:prune')->assertSuccessful();

        // Retention is measured on last use, not on age: a package every build
        // installs is never evicted however long ago it was first cached.
        $this->assertDatabaseMissing('mirrored_archives', ['id' => $cold->getKey()]);
        $this->assertDatabaseHas('mirrored_archives', ['id' => $warm->getKey()]);
        $this->assertFalse($disk->exists((string) $cold->path));
        $this->assertTrue($disk->exists((string) $warm->path));

        $this->assertDatabaseMissing('mirrored_packages', ['id' => $staleDocument->getKey()]);
        $this->assertDatabaseHas('mirrored_packages', ['id' => $usedDocument->getKey()]);
    }

    public function test_a_dry_run_reports_without_deleting(): void
    {
        $disk = $this->disk();

        $cold = $this->mirroredArchive($disk, 'symfony/console', str_repeat('a', 40), now()->subDays(90));

        $this->artisan('mirror:prune', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('mirrored_archives', ['id' => $cold->getKey()]);
        $this->assertTrue($disk->exists((string) $cold->path));
    }

    public function test_mirrored_files_that_no_row_claims_are_swept(): void
    {
        $disk = $this->disk();

        // What a delete cascading from a removed upstream leaves behind, and
        // what a crash between storing an archive and recording it leaves.
        $disk->put('mirror/9/symfony/orphan/'.str_repeat('c', 40).'.zip', 'nobody-claims-this');
        touch($disk->path('mirror/9/symfony/orphan/'.str_repeat('c', 40).'.zip'), now()->subDay()->getTimestamp());

        $kept = $this->mirroredArchive($disk, 'symfony/finder', str_repeat('b', 40));

        $this->artisan('mirror:prune')->assertSuccessful();

        $this->assertSame([(string) $kept->path], $disk->allFiles('mirror'));
    }

    public function test_a_freshly_written_mirrored_file_is_left_alone(): void
    {
        $disk = $this->disk();

        // Written this instant, which is what an archive whose row has not
        // been inserted yet looks like. Deleting it there would commit a row
        // whose file was already gone.
        $disk->put('mirror/9/symfony/racing/'.str_repeat('d', 40).'.zip', 'still-being-recorded');

        $this->artisan('mirror:prune')->assertSuccessful();

        $this->assertCount(1, $disk->allFiles('mirror'));
    }

    /**
     * The reason mirrored archives live under their own prefix. Both of these
     * commands reconcile one prefix against `package_versions`, a table that
     * knows nothing about the mirror — so a shared prefix would make every
     * cached archive an orphan to one and evidence of archive loss to the
     * other.
     */
    public function test_the_published_archive_commands_cannot_see_the_mirror(): void
    {
        $disk = $this->disk();

        $mirrored = $this->mirroredArchive($disk, 'symfony/console', str_repeat('a', 40));

        $disk->put('packages/acme/widgets/kept.zip', 'zip-bytes');

        $version = PackageVersion::factory()
            ->for(Package::factory()->create(['name' => 'acme/widgets']))
            ->create(['archive_path' => 'packages/acme/widgets/kept.zip', 'shasum' => sha1('zip-bytes')]);

        $this->artisan('archives:clean')->assertSuccessful();
        $this->artisan('archives:audit')->assertSuccessful();

        $this->assertTrue($disk->exists((string) $mirrored->path));
        $this->assertDatabaseHas('mirrored_archives', ['id' => $mirrored->getKey()]);

        // And the published side is still reconciled as it always was.
        $this->assertNotNull($version->refresh()->archive_path);
    }
}
