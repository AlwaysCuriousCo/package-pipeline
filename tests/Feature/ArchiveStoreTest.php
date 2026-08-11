<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PackageVersion;
use App\Services\ArchiveStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * The dist disk is shared by two sweeps that each own one prefix and delete
 * anything unclaimed inside it: archives:clean over `packages/`, mirror:prune
 * over `mirror/`. That separation is the only thing keeping them off each
 * other's files, so a path that lands in the wrong one is not a tidiness
 * problem — it is a file scheduled for deletion by a command that has no row
 * for it.
 */
class ArchiveStoreTest extends TestCase
{
    use RefreshDatabase;

    private string $zip;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.dists'));

        $this->zip = (string) tempnam(sys_get_temp_dir(), 'archive-store-test-');
        File::put($this->zip, 'zip-bytes');
    }

    protected function tearDown(): void
    {
        File::delete($this->zip);

        parent::tearDown();
    }

    public function test_an_archive_is_stored_under_the_published_prefix(): void
    {
        $version = $this->version('acme/widgets');

        app(ArchiveStore::class)->store($version, $this->zip);

        $this->assertStringStartsWith('packages/acme/widgets/', (string) $version->archive_path);
        Storage::disk(config('filesystems.dists'))->assertExists((string) $version->archive_path);
    }

    /**
     * Flysystem does not object to this on its own: it refuses a path that
     * climbs out of the disk root, and `packages/../mirror/...` does not — it
     * resolves quietly to a real place on the same disk that belongs to
     * somebody else's sweep.
     */
    public function test_a_name_that_climbs_into_the_mirror_prefix_is_refused(): void
    {
        $version = $this->version('../mirror/9/evil/pkg');

        try {
            app(ArchiveStore::class)->store($version, $this->zip);
            $this->fail('An archive path leaving the published prefix should have been refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('outside the [packages] prefix', $exception->getMessage());
        }

        $this->assertNull($version->refresh()->archive_path);
        $this->assertSame([], Storage::disk(config('filesystems.dists'))->allFiles());
    }

    /**
     * A path that resolves is stored as it resolves, so the string in the row
     * is the string on the disk — both sweeps compare them literally, and a
     * row that disagreed with the object would read as an orphan to one and as
     * a lost archive to the other.
     */
    public function test_the_stored_path_is_the_one_the_disk_will_use(): void
    {
        $version = $this->version('acme/./widgets');

        app(ArchiveStore::class)->store($version, $this->zip);

        $this->assertStringStartsWith('packages/acme/widgets/', (string) $version->archive_path);
        Storage::disk(config('filesystems.dists'))->assertExists((string) $version->archive_path);
    }

    /**
     * A version whose package carries the given name, written past the model's
     * own normalization so the store is asked the question directly.
     */
    private function version(string $name): PackageVersion
    {
        $package = Package::factory()->create();

        $package->forceFill(['name' => $name])->saveQuietly();

        return PackageVersion::factory()->for($package)->create(['archive_path' => null, 'shasum' => null]);
    }
}
