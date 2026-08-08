<?php

namespace Tests\Feature;

use App\Filament\Resources\Repositories\Pages\CreateRepository;
use App\Filament\Resources\Repositories\Pages\EditRepository;
use App\Filament\Resources\Repositories\Pages\ListRepositories;
use App\Models\Package;
use App\Models\Repository;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RepositoryResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_the_index_lists_repositories(): void
    {
        $repositories = Repository::factory()->count(3)->create();

        Livewire::test(ListRepositories::class)
            ->assertCanSeeTableRecords($repositories);
    }

    public function test_the_default_repository_lists_the_registry_root_not_a_bare_mount(): void
    {
        Repository::default();

        Livewire::test(ListRepositories::class)
            ->assertSee('/ (registry root)')
            ->assertDontSee('/r/');
    }

    public function test_a_repository_can_be_created(): void
    {
        Livewire::test(CreateRepository::class)
            ->fillForm([
                'name' => 'Internal packages',
                'path' => 'internal',
                'public' => false,
                'description' => 'Only for our own projects.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('repositories', [
            'name' => 'Internal packages',
            'path' => 'internal',
            'public' => false,
        ]);
    }

    public function test_a_created_repository_requires_a_path(): void
    {
        // Only the system creates the root repository; every admin-created
        // one is mounted under /r/{path}.
        Livewire::test(CreateRepository::class)
            ->fillForm(['name' => 'No path'])
            ->call('create')
            ->assertHasFormErrors(['path' => 'required']);
    }

    public function test_the_path_must_be_a_url_slug(): void
    {
        Livewire::test(CreateRepository::class)
            ->fillForm(['name' => 'Bad path', 'path' => 'Not A Slug!'])
            ->call('create')
            ->assertHasFormErrors(['path']);
    }

    public function test_the_path_and_name_must_be_unique(): void
    {
        Repository::factory()->create(['name' => 'Internal', 'path' => 'internal']);

        Livewire::test(CreateRepository::class)
            ->fillForm(['name' => 'Internal', 'path' => 'internal'])
            ->call('create')
            ->assertHasFormErrors(['name', 'path']);
    }

    public function test_the_default_repositorys_path_cannot_be_changed(): void
    {
        $default = Repository::default();

        // The field is disabled for the default repository, so whatever the
        // browser claims is never dehydrated into the saved state.
        Livewire::test(EditRepository::class, ['record' => $default->getKey()])
            ->fillForm(['name' => 'Renamed', 'path' => 'sneaky'])
            ->call('save')
            ->assertHasNoFormErrors();

        $default->refresh();

        $this->assertSame('Renamed', $default->name);
        $this->assertNull($default->path);
    }

    public function test_deleting_is_blocked_while_packages_remain(): void
    {
        $repository = Repository::factory()->create();
        Package::factory()->create(['repository_id' => $repository->id]);

        Livewire::test(ListRepositories::class)
            ->assertActionDisabled(TestAction::make('delete')->table($repository));
    }

    public function test_the_default_repository_cannot_be_deleted(): void
    {
        $default = Repository::default();

        Livewire::test(ListRepositories::class)
            ->assertActionDisabled(TestAction::make('delete')->table($default));
    }

    public function test_an_empty_named_repository_can_be_deleted(): void
    {
        $repository = Repository::factory()->create();

        Livewire::test(ListRepositories::class)
            ->callAction(TestAction::make('delete')->table($repository));

        $this->assertDatabaseMissing('repositories', ['id' => $repository->id]);
    }
}
