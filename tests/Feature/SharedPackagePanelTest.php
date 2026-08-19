<?php

namespace Tests\Feature;

use App\Filament\Resources\Packages\Pages\CreatePackage;
use App\Filament\Resources\Packages\Pages\EditPackage;
use App\Filament\Resources\Repositories\Pages\EditRepository;
use App\Filament\Resources\Repositories\RelationManagers\PackagesRelationManager;
use App\Jobs\SyncPackageJob;
use App\Models\Package;
use App\Models\Repository;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adding a package to another Composer repository from the panel — from the
 * package's own form, and from the repository it is being added to.
 */
class SharedPackagePanelTest extends TestCase
{
    use RefreshDatabase;

    private Repository $internal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->superAdmin()->create());

        $this->internal = Repository::factory()->create(['path' => 'internal']);
    }

    /**
     * @return Testable<PackagesRelationManager>
     */
    private function relationManager(): Testable
    {
        return Livewire::test(PackagesRelationManager::class, [
            'ownerRecord' => $this->internal,
            'pageClass' => EditRepository::class,
        ]);
    }

    public function test_the_form_shows_and_saves_the_repositories_a_package_is_served_from(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        Livewire::test(EditPackage::class, ['record' => $package->getRouteKey()])
            ->assertFormSet(['serving_repositories' => []])
            ->fillForm(['serving_repositories' => [$this->internal->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(
            [(int) $package->repository_id, (int) $this->internal->id],
            $package->fresh()->servingRepositoryIds(),
        );

        // And back out again, which is the same field left empty.
        Livewire::test(EditPackage::class, ['record' => $package->getRouteKey()])
            ->assertFormSet(['serving_repositories' => [$this->internal->id]])
            ->fillForm(['serving_repositories' => []])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([(int) $package->repository_id], $package->fresh()->servingRepositoryIds());
    }

    public function test_the_form_refuses_a_repository_that_already_serves_the_name(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);
        Package::factory()->create(['name' => 'acme/widgets', 'repository_id' => $this->internal->id]);

        Livewire::test(EditPackage::class, ['record' => $package->getRouteKey()])
            ->fillForm(['serving_repositories' => [$this->internal->id]])
            ->call('save')
            ->assertHasFormErrors(['serving_repositories']);

        $this->assertSame([(int) $package->repository_id], $package->fresh()->servingRepositoryIds());
    }

    public function test_a_package_can_be_created_into_several_repositories_at_once(): void
    {
        Queue::fake([SyncPackageJob::class]);

        Livewire::test(CreatePackage::class)
            ->fillForm([
                'name' => 'acme/widgets',
                'repository' => 'https://github.com/acme/widgets',
                'serving_repositories' => [$this->internal->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $package = Package::query()->where('name', 'acme/widgets')->sole();

        $this->assertEqualsCanonicalizing(
            [(int) $package->repository_id, (int) $this->internal->id],
            $package->servingRepositoryIds(),
        );
    }

    public function test_a_repository_can_serve_a_package_that_lives_elsewhere(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        $this->relationManager()
            ->assertCanNotSeeTableRecords([$package])
            ->callAction(TestAction::make('serveExisting')->table(), ['packages' => [$package->id]]);

        $this->assertContains((int) $this->internal->id, $package->fresh()->servingRepositoryIds());

        $this->relationManager()->assertCanSeeTableRecords([$package]);
    }

    public function test_a_repository_can_stop_serving_a_package_it_does_not_own(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);
        $package->serveFrom([$this->internal]);

        $this->relationManager()
            ->callAction(TestAction::make('stopServing')->table($package));

        $this->assertSame([(int) $package->repository_id], $package->fresh()->servingRepositoryIds());
    }

    public function test_a_repository_cannot_stop_serving_a_package_that_lives_there(): void
    {
        $package = Package::factory()->create([
            'name' => 'acme/widgets',
            'repository_id' => $this->internal->id,
        ]);

        $this->relationManager()
            ->assertActionHidden(TestAction::make('stopServing')->table($package));
    }
}
