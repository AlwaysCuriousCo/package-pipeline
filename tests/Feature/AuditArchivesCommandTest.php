<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\PackageVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
    private function version(string $version, bool $stored = true, ?Package $package = null): PackageVersion
    {
        $package ??= $this->package;

        $path = "packages/{$package->name}/{$version}.zip";

        if ($stored) {
            Storage::disk(config('filesystems.dists'))->put($path, 'zip-bytes');
        }

        return PackageVersion::factory()
            ->for($package)
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

    /**
     * The all-clear counts what was compared. A version inside the grace
     * window was never listed against the disk, so reporting it as confirmed
     * present would claim an answer the run does not have.
     *
     * Run through Artisan rather than the test harness because the assertion
     * is on the tail of the line: the harness pads and clips captured output
     * to a terminal width the real command is not writing to.
     */
    public function test_the_all_clear_counts_only_the_versions_it_compared(): void
    {
        $this->version('1.0.0');

        $this->version('1.2.0')->forceFill(['updated_at' => now()])->save();

        $this->assertSame(0, Artisan::call('archives:audit'));
        $this->assertStringContainsString('(1 checked, 1 too recent to check)', Artisan::output());
    }

    /**
     * The empty-disk refusal only ever caught the one shape where the disk
     * lists literally nothing, and a single file defeats it: one package
     * getting a tag at 03:00 stores one archive, and the 03:20 sweep then
     * reads a repointed bucket as the registry having lost everything else.
     *
     * Loss on that scale is not something a registry does one object at a
     * time, so the guard is proportional rather than all-or-nothing.
     */
    public function test_losing_almost_everything_reads_as_the_wrong_disk_rather_than_loss(): void
    {
        $this->version('1.0.0');

        foreach (range(1, 30) as $patch) {
            $this->version("2.0.{$patch}", stored: false);
        }

        $this->artisan('archives:audit')
            ->expectsOutputToContain('wrong one')
            ->assertFailed();

        $this->assertSame(31, PackageVersion::query()->whereNotNull('archive_path')->count());
    }

    /**
     * The other half of the same guard: a handful going missing is the shape
     * real loss actually has, and clearing it is the repair working as
     * designed. A registry small enough for a few losses to be a large share
     * of it must still be able to fix itself unattended.
     */
    public function test_a_handful_of_missing_archives_is_still_cleared(): void
    {
        $this->version('1.0.0');
        $this->version('1.1.0');

        foreach (['2.0.0', '2.0.1', '2.0.2'] as $version) {
            $this->version($version, stored: false);
        }

        $this->artisan('archives:audit')->assertSuccessful();

        $this->assertSame(2, PackageVersion::query()->whereNotNull('archive_path')->count());
    }

    /**
     * The operator who really did lose the bucket has to have a way through,
     * or the guard is a wall rather than a question.
     */
    public function test_force_clears_a_loss_the_guard_refused(): void
    {
        $this->version('1.0.0');

        foreach (range(1, 30) as $patch) {
            $this->version("2.0.{$patch}", stored: false);
        }

        $this->artisan('archives:audit', ['--force' => true])->assertSuccessful();

        $this->assertSame(1, PackageVersion::query()->whereNotNull('archive_path')->count());
    }

    /**
     * A dry run changes nothing, so there is nothing for the guard to protect
     * — and refusing would withhold exactly the list the refusal tells the
     * operator to go and read.
     */
    public function test_a_dry_run_reports_the_loss_the_guard_would_refuse(): void
    {
        $this->version('1.0.0');

        foreach (range(1, 30) as $patch) {
            $this->version("2.0.{$patch}", stored: false);
        }

        $this->artisan('archives:audit', ['--dry-run' => true])
            ->expectsOutputToContain('acme/widgets 2.0.30')
            ->assertSuccessful();

        $this->assertSame(31, PackageVersion::query()->whereNotNull('archive_path')->count());
    }

    /**
     * Clearing a version is only cheap because the next sync rebuilds it. A
     * package uploaded as an artifact has no repository to sync from at all,
     * so the columns are not a cache of anything — they are the only record of
     * which object held the bytes and what they hashed to, which is what a
     * restore from backup is done with.
     */
    public function test_an_uploaded_artifacts_version_is_reported_rather_than_cleared(): void
    {
        $uploaded = Package::factory()->create(['name' => 'acme/uploads', 'repository' => null]);

        $this->version('1.0.0');
        $lost = $this->version('1.1.0', stored: false, package: $uploaded);

        $this->artisan('archives:audit')
            ->expectsOutputToContain('no sync can rebuild it')
            ->assertSuccessful();

        $lost->refresh();
        $this->assertNotNull($lost->archive_path);
        $this->assertNotNull($lost->shasum);
    }

    /**
     * `package_versions.updated_at` is half of what /p2 cuts its ETag and
     * Last-Modified from. A mass clear that stamped it would tell every
     * Composer client in the estate to refetch metadata for every affected
     * package — to be handed a document advertising the same versions, still
     * pointing at a dist that still 404s. The repair moves the timestamp; the
     * clearing has nothing to announce.
     */
    public function test_clearing_does_not_move_the_metadata_validator(): void
    {
        $this->version('1.0.0');
        $lost = $this->version('1.1.0', stored: false);

        $before = $lost->updated_at;

        $this->artisan('archives:audit')->assertSuccessful();

        $this->assertNull($lost->refresh()->archive_path);
        $this->assertEquals($before, $lost->updated_at);
    }
}
