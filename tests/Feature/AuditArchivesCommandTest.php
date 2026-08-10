<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PackageVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The half of disk/database reconciliation that archives:clean does not do: a
 * version row whose archive is gone. Nothing in the request path notices one —
 * /p2 goes on advertising the version while dist answers 404 — and the sync
 * deliberately no longer looks, because looking cost a HEAD request per stored
 * version per sync.
 */
class AuditArchivesCommandTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.dists'));

        $this->package = Package::factory()->create(['name' => 'acme/widgets']);
    }

    /**
     * A version pointing at an archive, settled past the grace window — with
     * the file actually on the disk unless the case is about it being gone.
     */
    private function version(string $version, bool $stored = true): PackageVersion
    {
        $path = "packages/acme/widgets/{$version}.zip";

        if ($stored) {
            Storage::disk(config('filesystems.dists'))->put($path, 'zip-bytes');
        }

        return PackageVersion::factory()
            ->for($this->package)
            ->create([
                'version' => $version,
                'archive_path' => $path,
                'shasum' => sha1('zip-bytes'),
                'updated_at' => now()->subDay(),
            ]);
    }

    public function test_the_audit_clears_versions_whose_archive_is_gone(): void
    {
        $kept = $this->version('1.0.0');
        $lost = $this->version('1.1.0', stored: false);

        $this->artisan('archives:audit')
            ->expectsOutputToContain('acme/widgets 1.1.0')
            ->expectsOutputToContain('next sync')
            ->assertSuccessful();

        // Cleared, which is all the next sync needs to re-download it.
        $lost->refresh();
        $this->assertNull($lost->archive_path);
        $this->assertNull($lost->shasum);

        $this->assertNotNull($kept->refresh()->archive_path);
    }

    public function test_a_dry_run_reports_without_clearing(): void
    {
        $this->version('1.0.0');
        $lost = $this->version('1.1.0', stored: false);

        $this->artisan('archives:audit', ['--dry-run' => true])
            ->expectsOutputToContain('acme/widgets 1.1.0')
            ->expectsOutputToContain('dry run')
            ->assertSuccessful();

        $this->assertNotNull($lost->refresh()->archive_path);
    }

    /**
     * The import writes its archive inside the transaction that saves the row
     * pointing at it, so a version committed between this run's listing and
     * its query looks exactly like one whose file was lost.
     */
    public function test_a_version_written_moments_ago_is_left_alone(): void
    {
        $this->version('1.0.0');

        $committing = $this->version('1.2.0', stored: false);
        $committing->forceFill(['updated_at' => now()])->save();

        $this->artisan('archives:audit')
            ->doesntExpectOutputToContain('1.2.0')
            ->assertSuccessful();

        $this->assertNotNull($committing->refresh()->archive_path);
    }

    /**
     * A disk answering with nothing is far more likely to be misconfigured or
     * unreachable than a registry that lost every archive at once — and acting
     * on it would clear every version the registry serves.
     */
    public function test_an_empty_disk_is_treated_as_a_misconfiguration_rather_than_total_loss(): void
    {
        $version = $this->version('1.0.0', stored: false);

        $this->artisan('archives:audit')
            ->expectsOutputToContain('Refusing')
            ->assertFailed();

        $this->assertNotNull($version->refresh()->archive_path);
    }

    public function test_the_audit_says_so_when_every_archive_is_present(): void
    {
        $this->version('1.0.0');

        $this->artisan('archives:audit')
            ->expectsOutputToContain('where its version says it is')
            ->assertSuccessful();
    }
}
