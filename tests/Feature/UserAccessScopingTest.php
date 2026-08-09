<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Filament\Resources\Packages\PackageResource;
use App\Filament\Resources\Packages\Pages\ListPackages;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Row-level access for users without Unscoped:Package: public repositories
 * plus explicit grants, in the panel and through their personal tokens alike.
 */
class UserAccessScopingTest extends TestCase
{
    use RefreshDatabase;

    private Repository $internal;

    private Repository $other;

    private Package $widgets;

    private Package $gadgets;

    private Package $secret;

    private Package $open;

    protected function setUp(): void
    {
        parent::setUp();

        $this->internal = Repository::factory()->create(['path' => 'internal', 'public' => false]);
        $this->other = Repository::factory()->create(['path' => 'other', 'public' => false]);

        $this->widgets = $this->makeServedPackage('acme/widgets', $this->internal);
        $this->gadgets = $this->makeServedPackage('acme/gadgets', $this->internal);
        $this->secret = $this->makeServedPackage('acme/secret', $this->other);
        $this->open = $this->makeServedPackage('acme/open', Repository::default());
    }

    private function makeServedPackage(string $name, Repository $repository): Package
    {
        $package = Package::factory()->create(['name' => $name, 'repository_id' => $repository->id]);

        $package->versions()->create([
            'version' => 'v1.0.0',
            'reference' => sha1($name),
            'is_dev' => false,
            'metadata' => ['name' => $name, 'version' => 'v1.0.0'],
        ]);

        return $package;
    }

    /**
     * A panel user whose role can read packages but is not unscoped — the
     * shape of an external collaborator's account.
     */
    private function makeScopedUser(): User
    {
        $role = Role::findOrCreate('developer', 'web');
        $role->givePermissionTo(['ViewAny:Package', 'View:Package']);

        return tap(User::factory()->create())->assignRole($role);
    }

    public function test_a_scoped_user_sees_public_packages_and_their_grants_only(): void
    {
        $user = $this->makeScopedUser();
        $user->packages()->attach($this->widgets);

        $this->actingAs($user);

        Livewire::test(ListPackages::class)
            ->assertCanSeeTableRecords([$this->widgets, $this->open])
            ->assertCanNotSeeTableRecords([$this->gadgets, $this->secret]);
    }

    public function test_a_repository_grant_covers_all_of_its_packages(): void
    {
        $user = $this->makeScopedUser();
        $user->repositories()->attach($this->internal);

        $this->actingAs($user);

        Livewire::test(ListPackages::class)
            ->assertCanSeeTableRecords([$this->widgets, $this->gadgets, $this->open])
            ->assertCanNotSeeTableRecords([$this->secret]);
    }

    public function test_an_ungranted_package_page_is_out_of_reach_entirely(): void
    {
        $this->actingAs($this->makeScopedUser());

        $this->get(PackageResource::getUrl('view', ['record' => $this->gadgets]))
            ->assertNotFound();
    }

    public function test_a_super_admin_is_unscoped(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(ListPackages::class)
            ->assertCanSeeTableRecords([$this->widgets, $this->gadgets, $this->secret, $this->open]);
    }

    public function test_a_personal_token_inherits_its_owners_reach(): void
    {
        $user = $this->makeScopedUser();
        $user->packages()->attach($this->widgets);

        $new = Token::issue($user, 'laptop', [TokenAbility::RepositoryRead]);

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/internal/list.json')
            ->assertOk()
            ->assertExactJson(['packageNames' => ['acme/widgets']]);

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/other/p2/acme/secret.json')
            ->assertNotFound();

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/list.json')
            ->assertOk()
            ->assertExactJson(['packageNames' => ['acme/open']]);
    }

    public function test_vendor_patterns_name_only_vendors_the_token_can_reach(): void
    {
        $user = $this->makeScopedUser();
        $user->packages()->attach($this->makeServedPackage('granted/widgets', $this->internal));
        $this->makeServedPackage('hidden/widgets', $this->other);

        $new = Token::issue($user, 'laptop', [TokenAbility::RepositoryRead]);

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/internal/packages.json')
            ->assertOk()
            ->assertJsonPath('available-package-patterns', ['granted/*']);

        // A vendor in a repository this token was never granted must not
        // surface even as a prefix — the root would otherwise disclose who
        // publishes here to anyone holding any token at all.
        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/other/packages.json')
            ->assertOk()
            ->assertJsonPath('available-package-patterns', []);
    }

    public function test_a_users_token_without_grants_still_reads_public_repositories(): void
    {
        $new = Token::issue($this->makeScopedUser(), 'laptop', [TokenAbility::RepositoryRead]);

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/list.json')
            ->assertOk()
            ->assertExactJson(['packageNames' => ['acme/open']]);

        $this->withBasicAuth('token', $new->plainText)
            ->getJson('/r/internal/list.json')
            ->assertOk()
            ->assertExactJson(['packageNames' => []]);
    }
}
