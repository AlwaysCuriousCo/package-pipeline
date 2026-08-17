<?php

namespace Tests\Feature;

use App\Filament\Resources\Packages\Pages\ViewPackage;
use App\Filament\Resources\Packages\RelationManagers\VersionsRelationManager;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Repository;
use App\Models\User;
use App\Support\VersionNormalizer;
use DateTimeInterface;
use Filament\Actions\Testing\TestAction;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter as FlysystemLocalAdapter;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Taking a version's stored zip out of the panel: who may, what they get, and
 * what happens when the row's path has outlived its file.
 */
class VersionArchiveDownloadTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.dists'));

        $this->package = Package::factory()->create(['name' => 'acme/widgets']);
    }

    private function version(string $version, ?string $path = 'packages/acme/widgets/one.zip'): PackageVersion
    {
        if ($path !== null) {
            Storage::disk(config('filesystems.dists'))->put($path, 'zip-bytes');
        }

        return $this->package->versions()->create([
            'version' => $version,
            'order' => (new VersionNormalizer)->order($version),
            'reference' => sha1($version),
            'is_dev' => str_starts_with($version, 'dev-'),
            'archive_path' => $path,
            'metadata' => ['name' => 'acme/widgets', 'version' => $version],
        ]);
    }

    public function test_an_admin_downloads_the_stored_zip(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $version = $this->version('1.5.0');

        $response = $this->get(route('downloads.version', $version));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');
        $response->assertDownload('widgets-1.5.0.zip');
        $this->assertSame('zip-bytes', $response->streamedContent());
    }

    /**
     * A branch build is stored as `dev-feat/enhance`, and Composer allows more
     * besides — none of which belongs in a Content-Disposition header as it
     * stands.
     */
    public function test_a_branch_versions_filename_is_reduced_to_something_saveable(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $version = $this->version('dev-feat/enhance');

        $this->get(route('downloads.version', $version))
            ->assertDownload('widgets-dev-feat-enhance.zip');
    }

    /**
     * A disk with URLs of its own takes the transfer, and then it is the disk
     * naming the file the operator saves. Stored paths are uuids, so a
     * redirect that asks for nothing hands over `019ff239-....zip` — the same
     * archive under a name nobody can tell from any other.
     */
    public function test_a_redirect_to_object_storage_still_names_the_download(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $options = [];

        $root = storage_path('framework/testing/disks/signing');
        File::cleanDirectory($root);

        $adapter = new FlysystemLocalAdapter($root);
        $disk = new FilesystemAdapter(new Flysystem($adapter), $adapter);

        // By reference because Laravel rebinds the callback to the disk it
        // belongs to, which leaves the test's own $this out of reach.
        $disk->buildTemporaryUrlsUsing(
            function (string $path, DateTimeInterface $expiration, array $given) use (&$options): string {
                $options = $given;

                return "https://objects.test/{$path}";
            },
        );

        Storage::set('s3', $disk);
        config(['filesystems.dists' => 's3']);

        $version = $this->version('1.5.0');

        $this->get(route('downloads.version', $version))->assertRedirect();

        $this->assertSame(
            ['ResponseContentDisposition' => 'attachment; filename="widgets-1.5.0.zip"'],
            $options,
        );
    }

    /**
     * An archive that left the registry is a download whoever asked for it, so
     * this lands in the same counters and the same history the dist endpoint
     * writes — with no token prefix, because none was presented.
     */
    public function test_an_archive_taken_from_the_panel_counts_as_a_download(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $version = $this->version('1.5.0');

        $this->get(route('downloads.version', $version))->assertOk();

        $this->assertSame(1, (int) $version->refresh()->total_downloads);
        $this->assertSame(1, (int) $this->package->refresh()->total_downloads);
        $this->assertDatabaseHas('downloads', [
            'package_id' => $this->package->getKey(),
            'package_version_id' => $version->getKey(),
            'version' => '1.5.0',
            'token_prefix' => null,
        ]);
    }

    /**
     * Laravel answers HEAD on every GET route and drops the body far below the
     * controller, so a link checker or an uptime probe must not land in the
     * counters as a download that took no bytes.
     */
    public function test_a_head_request_is_not_counted(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $version = $this->version('1.5.0');

        $this->head(route('downloads.version', $version))->assertOk();

        $this->assertSame(0, (int) $version->refresh()->total_downloads);
        $this->assertDatabaseCount('downloads', 0);
    }

    /**
     * The counters must not move for a request the archive never came back
     * from — a 404 is not a download.
     */
    public function test_a_missing_archive_is_not_counted(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $version = $this->version('1.5.0', path: null);

        $this->get(route('downloads.version', $version))->assertNotFound();

        $this->assertDatabaseCount('downloads', 0);
    }

    public function test_a_version_with_no_stored_archive_is_a_404(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $version = $this->version('1.5.0', path: null);

        $this->get(route('downloads.version', $version))->assertNotFound();
    }

    /**
     * A path can outlive its file — storage lost on a redeploy, an object
     * deleted out from under us. Saying so beats handing over a link to
     * nothing.
     */
    public function test_a_path_whose_file_has_gone_is_a_404_rather_than_an_empty_zip(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $version = $this->version('1.5.0');

        Storage::disk(config('filesystems.dists'))->delete((string) $version->archive_path);

        $this->get(route('downloads.version', $version))->assertNotFound();
    }

    public function test_an_anonymous_request_gets_no_archive(): void
    {
        $version = $this->version('1.5.0');

        $this->get(route('downloads.version', $version))->assertRedirect();
    }

    /**
     * The route is addressed by row id, so it has to make the same visibility
     * decision the package table does — otherwise it is a way to read the
     * contents of every private package by counting upwards.
     */
    public function test_a_package_the_admin_cannot_see_reads_as_missing(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::create(['name' => 'observer', 'guard_name' => 'web']));
        $user->givePermissionTo('View:Package');

        $this->actingAs($user);

        $this->package->composerRepository()->associate(
            Repository::factory()->create(['public' => false]),
        )->save();

        $version = $this->version('1.5.0');

        $this->get(route('downloads.version', $version))->assertNotFound();
    }

    public function test_the_versions_table_offers_the_download_only_when_an_archive_is_stored(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $stored = $this->version('1.5.0');
        $unbuilt = $this->version('1.4.1', path: null);

        Livewire::test(VersionsRelationManager::class, [
            'ownerRecord' => $this->package,
            'pageClass' => ViewPackage::class,
        ])
            ->assertActionVisible(TestAction::make('downloadArchive')->table($stored))
            ->assertActionHidden(TestAction::make('downloadArchive')->table($unbuilt));
    }
}
