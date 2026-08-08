<?php

namespace Tests\Feature;

use App\Filament\Resources\Packages\Pages\ListPackages;
use App\Jobs\SyncPackageJob;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One-click onboarding from a source's project browser: each picked project
 * becomes a package with its first sync queued.
 */
class ImportFromSourceTest extends TestCase
{
    use RefreshDatabase;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->superAdmin()->create());

        $this->source = Source::factory()->withToken()->create(['account' => 'acme']);

        // The select validates chosen values against the browsable projects,
        // so the provider listing has to answer during the action call too.
        Http::fake([
            'api.github.com/orgs/acme/repos*' => Http::response([
                ['id' => 1, 'full_name' => 'acme/widgets', 'html_url' => 'https://github.com/acme/widgets'],
                ['id' => 2, 'full_name' => 'acme/gadgets', 'html_url' => 'https://github.com/acme/gadgets'],
            ]),
        ]);
    }

    public function test_selected_projects_become_packages_with_their_first_sync_queued(): void
    {
        Queue::fake();

        Livewire::test(ListPackages::class)
            ->callAction('importFromSource', [
                'source_id' => $this->source->id,
                'projects' => [
                    'https://github.com/acme/widgets',
                    'https://github.com/acme/gadgets',
                ],
                'repository_id' => Repository::default()->id,
                'create_webhook' => false,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $widgets = Package::query()->where('repository', 'https://github.com/acme/widgets')->sole();

        $this->assertSame('acme/widgets', $widgets->name);
        $this->assertSame($this->source->id, $widgets->source_id);
        $this->assertTrue($widgets->composerRepository->isDefault());

        $this->assertSame(2, Package::query()->count());

        Queue::assertPushed(SyncPackageJob::class, 2);
    }

    public function test_projects_already_onboarded_are_skipped_not_duplicated(): void
    {
        Queue::fake();

        Package::factory()->create(['repository' => 'https://github.com/acme/widgets']);

        Livewire::test(ListPackages::class)
            ->callAction('importFromSource', [
                'source_id' => $this->source->id,
                'projects' => [
                    'https://github.com/acme/widgets',
                    'https://github.com/acme/gadgets',
                ],
                'repository_id' => Repository::default()->id,
                'create_webhook' => false,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(2, Package::query()->count());

        // Only the new project syncs; the existing one was left alone.
        Queue::assertPushed(SyncPackageJob::class, 1);
    }

    public function test_importing_can_arrange_the_webhook_per_project(): void
    {
        Queue::fake();

        Http::fake([
            'api.github.com/repos/acme/widgets/hooks' => Http::response(['id' => 55], 201),
        ]);

        Livewire::test(ListPackages::class)
            ->callAction('importFromSource', [
                'source_id' => $this->source->id,
                'projects' => ['https://github.com/acme/widgets'],
                'repository_id' => Repository::default()->id,
                'create_webhook' => true,
            ])
            ->assertHasNoActionErrors();

        $package = Package::query()->where('repository', 'https://github.com/acme/widgets')->sole();

        $this->assertSame(55, $package->webhook_id);
    }

    public function test_one_refused_project_does_not_abort_the_rest(): void
    {
        Queue::fake();

        // Same composer name, different URL: the unique (repository, name)
        // index refuses the import of widgets-fork under the guessed name.
        Package::factory()->create([
            'name' => 'acme/widgets',
            'repository' => 'https://github.com/acme/widgets-old',
        ]);

        Livewire::test(ListPackages::class)
            ->callAction('importFromSource', [
                'source_id' => $this->source->id,
                'projects' => [
                    'https://github.com/acme/widgets',
                    'https://github.com/acme/gadgets',
                ],
                'repository_id' => Repository::default()->id,
                'create_webhook' => false,
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        // The collision was reported, the other project still landed.
        $this->assertNotNull(Package::query()->where('repository', 'https://github.com/acme/gadgets')->first());
        $this->assertNull(Package::query()->where('repository', 'https://github.com/acme/widgets')->first());

        Queue::assertPushed(SyncPackageJob::class, 1);
    }
}
