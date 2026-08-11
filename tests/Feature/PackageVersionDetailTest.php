<?php

namespace Tests\Feature;

use App\Filament\Resources\Packages\Pages\ViewPackage;
use App\Filament\Resources\Packages\RelationManagers\VersionsRelationManager;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use App\Support\VersionNormalizer;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The versions list on a package's page: what it shows about a version, and
 * the one repair it offers.
 */
class PackageVersionDetailTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->superAdmin()->create());

        $this->package = Package::factory()->create(['name' => 'acme/widgets']);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function version(string $version, array $metadata = [], int $downloads = 0): PackageVersion
    {
        return $this->package->versions()->create([
            'version' => $version,
            'order' => (new VersionNormalizer)->order($version),
            'reference' => sha1($version),
            'is_dev' => false,
            'total_downloads' => $downloads,
            'metadata' => ['name' => 'acme/widgets', 'version' => $version, ...$metadata],
        ]);
    }

    /**
     * @return Testable<VersionsRelationManager>
     */
    private function relationManager(): Testable
    {
        return Livewire::test(VersionsRelationManager::class, [
            'ownerRecord' => $this->package,
            'pageClass' => ViewPackage::class,
        ]);
    }

    public function test_versions_are_listed_newest_first_semantically(): void
    {
        // The bug the `order` column exists for: sorted as strings, "1.9.0"
        // comes after "1.10.0" and the newest release lands mid-list.
        $nine = $this->version('1.9.0');
        $ten = $this->version('1.10.0');

        $this->relationManager()
            ->assertCanSeeTableRecords([$ten, $nine], inOrder: true);
    }

    public function test_a_version_with_no_order_sorts_last_rather_than_wherever_the_database_puts_it(): void
    {
        $ordered = $this->version('1.0.0');

        // A row synced before the column existed, until the next sync
        // backfills it.
        $unordered = $this->version('2.0.0');
        $unordered->forceFill(['order' => null])->save();

        $this->relationManager()
            ->assertCanSeeTableRecords([$ordered, $unordered], inOrder: true);
    }

    public function test_the_table_shows_each_versions_own_download_count(): void
    {
        $this->version('1.0.0', downloads: 42);

        $this->relationManager()
            ->assertCanRenderTableColumn('total_downloads')
            ->assertSee('42');
    }

    public function test_a_version_can_be_opened_for_its_stored_metadata(): void
    {
        $version = $this->version('1.0.0');

        $this->relationManager()
            ->mountAction(TestAction::make('view')->table($version))
            ->assertActionMounted(TestAction::make('view')->table($version));
    }

    public function test_a_version_reads_its_requirements_out_of_the_stored_metadata(): void
    {
        $version = $this->version('1.0.0', [
            'license' => 'MIT',
            'authors' => [
                ['name' => 'Jo Packager', 'email' => 'jo@example.com'],
                ['name' => 'Sam Reviewer'],
            ],
            'require' => ['php' => '^8.3', 'acme/support' => '^2.0'],
            'require-dev' => ['phpunit/phpunit' => '^12.0'],
        ]);

        // Composer allows a bare string here as well as a list, and both mean
        // the same thing to a consumer.
        $this->assertSame(['MIT'], $version->licenses());
        $this->assertSame(['Jo Packager <jo@example.com>', 'Sam Reviewer'], $version->authorLines());
        $this->assertSame(['php: ^8.3', 'acme/support: ^2.0'], $version->requirements());
        $this->assertSame(['phpunit/phpunit: ^12.0'], $version->requirements('require-dev'));
    }

    public function test_a_version_that_declares_none_of_it_reads_as_empty_rather_than_erroring(): void
    {
        // Nothing constrains what a repository puts in its composer.json, and
        // a malformed block must not take the page down.
        $version = $this->version('1.0.0', [
            'license' => null,
            'authors' => 'Jo Packager',
            'require' => 'php',
        ]);

        $this->assertSame([], $version->licenses());
        $this->assertSame([], $version->authorLines());
        $this->assertSame([], $version->requirements());
        $this->assertSame([], $version->requirements('require-dev'));
    }

    public function test_a_stuck_version_can_be_deleted(): void
    {
        $version = $this->version('1.0.0');

        $this->relationManager()
            ->callAction(TestAction::make('delete')->table($version))
            ->assertHasNoActionErrors();

        $this->assertDatabaseMissing('package_versions', ['id' => $version->getKey()]);
    }

    public function test_deleting_a_version_borrows_the_packages_update_permission(): void
    {
        // Versions have no Shield permissions of their own: unpublishing one
        // is a change to the package, so a role that may look at packages but
        // not change them must not be able to.
        $user = User::factory()->create();
        $user->assignRole(Role::create(['name' => 'observer', 'guard_name' => 'web']));
        $user->givePermissionTo('View:Package');

        $this->actingAs($user);

        $version = $this->version('1.0.0');

        $this->relationManager()
            ->assertActionHidden(TestAction::make('delete')->table($version));
    }
}
