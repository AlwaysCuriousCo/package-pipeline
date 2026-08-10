<?php

namespace Tests\Feature;

use App\Filament\Resources\Repositories\Pages\CreateRepository;
use App\Filament\Resources\Repositories\Pages\EditRepository;
use App\Filament\Resources\Repositories\Pages\ListRepositories;
use App\Filament\Resources\Repositories\RepositoryResource;
use App\Models\MirroredArchive;
use App\Models\MirroredPackage;
use App\Models\Package;
use App\Models\Repository;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
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

    /**
     * On purpose, and the one place in the panel where a grant does not narrow
     * a list. Package grants say what somebody may read; this screen is what
     * the registry is made of, and ViewAny:Repository is the permission that
     * decides who configures that. An operator holding it and no grant at all
     * would otherwise be shown an empty list of the mounts they administer.
     *
     * @see RepositoryResource
     */
    public function test_the_index_is_not_narrowed_by_the_operators_own_grants(): void
    {
        $role = Role::findOrCreate('operator', 'web');
        $role->givePermissionTo(['ViewAny:Repository', 'View:Repository', 'Update:Repository']);

        $this->actingAs(tap(User::factory()->create())->assignRole($role));

        $private = Repository::factory()->create(['path' => 'internal', 'public' => false]);

        Livewire::test(ListRepositories::class)->assertCanSeeTableRecords([$private]);
    }

    public function test_the_default_repository_lists_the_registry_root_not_a_bare_mount(): void
    {
        Repository::default();

        Livewire::test(ListRepositories::class)
            ->assertSee('/ (registry root)')
            ->assertDontSee('/r/');
    }

    public function test_upstreams_are_configured_on_the_repository(): void
    {
        $repository = Repository::factory()->create(['path' => 'internal']);

        Livewire::test(EditRepository::class, ['record' => $repository->getKey()])
            ->fillForm(['upstreams' => [[
                'name' => 'packagist.org',
                'url' => 'https://repo.packagist.org/',
                'token' => 'upstream-secret',
                'enabled' => true,
            ]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $upstream = $repository->upstreams()->sole();

        // The trailing slash is normalised away on the model, so the same
        // upstream typed two ways cannot get past the unique index twice.
        $this->assertSame('https://repo.packagist.org', $upstream->url);
        $this->assertSame('upstream-secret', $upstream->token);
        $this->assertTrue($repository->refresh()->mirrors());
    }

    public function test_the_stored_upstream_token_is_never_echoed_back_to_the_browser(): void
    {
        $repository = Repository::factory()->create(['path' => 'internal']);
        $repository->upstreams()->create([
            'name' => 'packagist.org',
            'url' => 'https://repo.packagist.org',
            'token' => 'upstream-secret',
        ]);

        Livewire::test(EditRepository::class, ['record' => $repository->getKey()])
            ->assertDontSee('upstream-secret')
            // A blank input keeps what is stored rather than clearing it.
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('upstream-secret', $repository->upstreams()->sole()->token);
    }

    public function test_the_index_shows_what_the_mirror_is_holding(): void
    {
        $repository = Repository::factory()->create(['path' => 'internal']);
        $upstream = $repository->upstreams()->create(['name' => 'packagist.org', 'url' => 'https://repo.packagist.org']);

        MirroredPackage::factory()->create(['upstream_id' => $upstream->getKey()]);

        foreach ([str_repeat('a', 40), str_repeat('b', 40)] as $reference) {
            MirroredArchive::factory()->create(['upstream_id' => $upstream->getKey(), 'reference' => $reference]);
        }

        // The Composer endpoints deliberately do not enumerate the cache, so
        // this is the only place an operator can see what it costs.
        Livewire::test(ListRepositories::class)
            ->assertSee('1 docs / 2 zips');
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

    /**
     * Whether the delete action is available is a question about the same
     * count the Packages column already shows, so it is answered off the
     * record rather than asked again — twice — for every row.
     */
    public function test_listing_repositories_costs_the_same_however_many_there_are(): void
    {
        Package::factory()->create(['repository_id' => Repository::factory()->create()->id]);

        // Rendered once before anything is measured: the panel resolves
        // permissions and settings on first render and caches them, which
        // would otherwise show up as the difference this is looking for.
        $this->renderRepositoryList();

        $one = $this->renderRepositoryList();

        foreach (range(1, 4) as $ignored) {
            Package::factory()->create(['repository_id' => Repository::factory()->create()->id]);
        }

        $this->assertSame($one, $this->renderRepositoryList());
    }

    /**
     * How many queries one render of the repository list runs.
     */
    private function renderRepositoryList(): int
    {
        $queries = 0;

        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        Livewire::test(ListRepositories::class)->assertOk();

        // The listener cannot be removed, so each call measures itself against
        // its own counter and the earlier ones keep counting into theirs.
        return $queries;
    }
}
