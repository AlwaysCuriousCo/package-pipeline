<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Filament\Resources\Packages\Pages\CreatePackage;
use App\Filament\Resources\Repositories\Pages\EditRepository;
use App\Jobs\SyncPackageJob;
use App\Models\DeployToken;
use App\Models\Package;
use App\Models\Repository;
use App\Models\ReservedVendor;
use App\Models\Token;
use App\Models\User;
use App\Services\PackageSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * Vendor namespace protection: a dependency-confusion defence, server half.
 *
 * A consuming project that lists this registry alongside packagist.org resolves
 * a name from whichever repository answers for it. Reserving `acme` for one
 * Composer repository is how an operator says that only that repository may
 * introduce names under it — closing the door on a mistake, or a credential
 * with reach it should not have, quietly publishing `acme/anything` here.
 *
 * The consumer half is configuration in each project's composer.json and lives
 * in docs/dependency-confusion.md; nothing on the server can substitute for it.
 */
class ReservedVendorTest extends TestCase
{
    use RefreshDatabase;

    private Repository $owner;

    private Repository $other;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.dists' => 's3']);
        Storage::fake('s3');

        $this->owner = Repository::factory()->create(['path' => 'owner', 'name' => 'Owned']);
        $this->other = Repository::factory()->create(['path' => 'other', 'name' => 'Other']);

        $this->owner->reservedVendors()->create(['vendor' => 'acme']);
    }

    /**
     * @param  array<string, mixed>  $composerJson
     */
    private function makeZip(array $composerJson): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'upload-zip-');

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('composer.json', (string) json_encode($composerJson));
        $zip->close();

        return new UploadedFile($path, 'package.zip', 'application/zip', test: true);
    }

    /**
     * A CI credential with the run of the whole registry, which is what makes
     * the refusals below about the vendor rather than about the grant.
     */
    private function writeToken(): string
    {
        return Token::issue(
            DeployToken::factory()->create(),
            'ci',
            [TokenAbility::RepositoryWrite, TokenAbility::ApiRead, TokenAbility::ApiWrite],
        )->plainText;
    }

    public function test_a_reserved_vendor_cannot_be_uploaded_into_from_another_repository(): void
    {
        $response = $this->withToken($this->writeToken())
            ->post('/r/other/upload/acme/widgets', [
                'file' => $this->makeZip(['name' => 'acme/widgets', 'version' => '1.0.0']),
            ])
            ->assertForbidden();

        $this->assertStringContainsString('reserved by the "Owned"', (string) $response->json('message'));
        $this->assertDatabaseCount('packages', 0);
    }

    public function test_the_owning_repository_publishes_the_reserved_vendor_as_before(): void
    {
        $this->withToken($this->writeToken())
            ->post('/r/owner/upload/acme/widgets', [
                'file' => $this->makeZip(['name' => 'acme/widgets', 'version' => '1.0.0']),
            ])
            ->assertCreated();

        $this->assertSame($this->owner->id, Package::query()->sole()->repository_id);
    }

    public function test_an_unreserved_vendor_is_unaffected(): void
    {
        $this->withToken($this->writeToken())
            ->post('/r/other/upload/globex/widgets', [
                'file' => $this->makeZip(['name' => 'globex/widgets', 'version' => '1.0.0']),
            ])
            ->assertCreated();
    }

    /**
     * A reservation governs what may be *introduced* under a vendor. Breaking
     * the pipeline of a package that predates one is not what protecting a
     * namespace is supposed to cost.
     */
    public function test_a_package_that_predates_the_reservation_keeps_publishing(): void
    {
        $package = Package::factory()->create([
            'name' => 'globex/widgets',
            'repository_id' => $this->other->id,
        ]);

        $this->owner->reservedVendors()->create(['vendor' => 'globex']);

        $this->withToken($this->writeToken())
            ->post('/r/other/upload/globex/widgets', [
                'file' => $this->makeZip(['name' => 'globex/widgets', 'version' => '2.0.0']),
            ])
            ->assertCreated();

        $this->assertSame('2.0.0', $package->fresh()?->latest_version);
    }

    public function test_the_panel_refuses_a_package_under_another_repositorys_reserved_vendor(): void
    {
        Queue::fake([SyncPackageJob::class]);

        Http::fake([
            'api.github.com/repos/acme/widgets/contents/composer.json*' => Http::response(['name' => 'acme/widgets']),
        ]);

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(CreatePackage::class)
            ->fillForm(['repository' => 'https://github.com/acme/widgets'])
            ->goToNextWizardStep()
            ->fillForm(['name' => 'acme/widgets', 'repository_id' => $this->other->id])
            ->call('create')
            ->assertHasFormErrors(['name']);

        $this->assertDatabaseCount('packages', 0);

        // The same package, in the repository that owns the vendor.
        Livewire::test(CreatePackage::class)
            ->fillForm(['repository' => 'https://github.com/acme/widgets'])
            ->goToNextWizardStep()
            ->fillForm(['name' => 'acme/widgets', 'repository_id' => $this->owner->id])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseCount('packages', 1);
    }

    public function test_the_api_refuses_a_package_under_another_repositorys_reserved_vendor(): void
    {
        Queue::fake([SyncPackageJob::class]);

        $plain = $this->writeToken();

        $this->withToken($plain)
            ->postJson('/api/v1/packages', [
                'url' => 'https://github.com/acme/widgets',
                'repository' => 'other',
                'webhook' => false,
                'sync' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->withToken($plain)
            ->postJson('/api/v1/packages', [
                'url' => 'https://github.com/acme/widgets',
                'repository' => 'owner',
                'webhook' => false,
                'sync' => false,
            ])
            ->assertCreated();
    }

    public function test_the_console_refuses_a_package_under_another_repositorys_reserved_vendor(): void
    {
        Queue::fake([SyncPackageJob::class]);

        $this->artisan('package:add', [
            'repository' => 'https://github.com/acme/widgets',
            '--repo' => 'other',
            '--no-webhook' => true,
            '--no-sync' => true,
        ])->assertFailed();

        $this->assertDatabaseCount('packages', 0);
    }

    /**
     * The model's own guard, which is what every path above checks before so
     * that this one never has to speak — including the paths that have no form
     * and no request behind them.
     */
    public function test_a_sync_will_not_rename_a_package_into_a_reserved_vendor(): void
    {
        $package = Package::factory()->create([
            'name' => 'globex/placeholder',
            'repository_id' => $this->other->id,
            'repository' => 'https://github.com/globex/widgets',
        ]);

        Http::fake([
            // The repository's composer.json claims a name under somebody
            // else's vendor — a fork, a mistake, or a hijack, and nothing here
            // can tell which.
            'api.github.com/repos/globex/widgets/contents/composer.json*' => Http::response(['name' => 'acme/widgets']),
        ]);

        $this->expectExceptionMessage('reserved by the "Owned"');

        app(PackageSynchronizer::class)->resolveComposerName($package);
    }

    public function test_a_vendor_is_reserved_however_it_is_typed(): void
    {
        $reservation = $this->other->reservedVendors()->create(['vendor' => 'Globex/*']);

        $this->assertSame('globex', $reservation->vendor);
        $this->assertNull(ReservedVendor::conflictFor('globex/widgets', $this->other->id));
        $this->assertSame($reservation->id, ReservedVendor::conflictFor('globex/widgets', $this->owner->id)?->id);
    }

    public function test_the_panel_reserves_and_releases_vendors_on_the_repository(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(EditRepository::class, ['record' => $this->other->getRouteKey()])
            ->fillForm(['reservedVendors' => [['vendor' => 'globex']]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['globex'], $this->other->reservedVendors()->pluck('vendor')->all());

        // Two repositories claiming one vendor is the ambiguity a reservation
        // exists to remove, so it is refused rather than stored.
        Livewire::test(EditRepository::class, ['record' => $this->other->getRouteKey()])
            ->fillForm(['reservedVendors' => [['vendor' => 'globex'], ['vendor' => 'acme']]])
            ->call('save')
            ->assertHasFormErrors();

        Livewire::test(EditRepository::class, ['record' => $this->other->getRouteKey()])
            ->fillForm(['reservedVendors' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0, $this->other->reservedVendors()->count());
    }

    public function test_deleting_a_repository_releases_its_reservations(): void
    {
        $this->owner->delete();

        $this->assertDatabaseCount('reserved_vendors', 0);
    }
}
