<?php

namespace Tests\Feature;

use App\Filament\Resources\Packages\PackageResource;
use App\Models\AuthenticationSource;
use App\Models\DeployToken;
use App\Models\Package;
use App\Models\PackageAdvisory;
use App\Models\Repository;
use App\Models\Source;
use App\Models\Token;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The permission matrix behind the policies.
 *
 * Every policy method is one line delegating to one permission, which is
 * exactly the kind of code a copy-paste quietly gets wrong: a `delete` that
 * checks `Delete:Source` grants deletion to anyone who can delete a source,
 * and nothing else in the suite would notice. So each ability is granted
 * alone and the whole matrix is asserted against it — what it opens, and
 * everything it must leave shut.
 */
class PolicyPermissionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every ability the generated policies declare, in Shield's spelling:
     * the permission is the ability with its first letter capitalised, then
     * the entity.
     *
     * @var list<string>
     */
    private const ABILITIES = [
        'viewAny', 'view', 'create', 'update', 'delete', 'deleteAny',
        'restore', 'restoreAny', 'forceDelete', 'forceDeleteAny',
        'replicate', 'reorder',
    ];

    /**
     * The abilities that decide about one record rather than the resource,
     * and so are asked with an instance instead of the class.
     *
     * @var list<string>
     */
    private const RECORD_ABILITIES = ['view', 'update', 'delete', 'restore', 'forceDelete', 'replicate'];

    /**
     * The entities Shield generated permissions for, named as they appear in
     * the permission rows.
     *
     * @return iterable<string, array{string}>
     */
    public static function entities(): iterable
    {
        foreach (['Package', 'Source', 'Repository', 'User', 'DeployToken', 'Token', 'AuthenticationSource', 'Role'] as $entity) {
            yield $entity => [$entity];
        }
    }

    /**
     * A record of the given entity, for the abilities that need one.
     */
    private function record(string $entity): Model
    {
        return match ($entity) {
            'Package' => Package::factory()->create(),
            'Source' => Source::factory()->create(),
            'Repository' => Repository::factory()->create(),
            'User' => User::factory()->create(),
            'DeployToken' => DeployToken::factory()->create(),
            'Token' => Token::factory()->create(),
            'AuthenticationSource' => AuthenticationSource::factory()->create(),
            'Role' => Role::create(['name' => 'subject', 'guard_name' => 'web']),
            // An entity added to the provider without a record here would
            // otherwise skip every ability that decides about one.
            default => throw new InvalidArgumentException("No record recipe for {$entity}."),
        };
    }

    /**
     * A panel user whose role holds exactly the named permissions.
     *
     * @param  list<string>  $permissions
     */
    private function userGranted(array $permissions): User
    {
        $role = Role::create(['name' => 'granted-'.Role::query()->count(), 'guard_name' => 'web']);
        $role->givePermissionTo($permissions);

        return tap(User::factory()->create())->assignRole($role);
    }

    /**
     * @return list<string>
     */
    private function permissionsFor(string $entity): array
    {
        return array_map(fn (string $ability): string => ucfirst($ability).':'.$entity, self::ABILITIES);
    }

    /**
     * Whether the user may do this, asked the way Filament asks it.
     */
    private function allows(User $user, string $ability, Model $record): bool
    {
        return Gate::forUser($user)->allows(
            $ability,
            in_array($ability, self::RECORD_ABILITIES, true) ? $record : $record::class,
        );
    }

    #[DataProvider('entities')]
    public function test_each_ability_answers_to_its_own_permission_and_no_other(string $entity): void
    {
        $record = $this->record($entity);

        foreach (self::ABILITIES as $granted) {
            $permission = ucfirst($granted).':'.$entity;
            $user = $this->userGranted([$permission]);

            foreach (self::ABILITIES as $asked) {
                $this->assertSame(
                    $granted === $asked,
                    $this->allows($user, $asked, $record),
                    "Holding {$permission} answered wrongly for {$asked} on {$entity}.",
                );
            }
        }
    }

    #[DataProvider('entities')]
    public function test_holding_no_permission_at_all_opens_nothing(string $entity): void
    {
        $record = $this->record($entity);
        $user = $this->userGranted([]);

        foreach (self::ABILITIES as $ability) {
            $this->assertFalse(
                $this->allows($user, $ability, $record),
                "A role with no permissions was allowed {$ability} on {$entity}.",
            );
        }
    }

    /**
     * Entities are administered separately — someone trusted with packages is
     * not thereby trusted with the accounts that reach them.
     */
    #[DataProvider('entities')]
    public function test_every_permission_for_one_entity_grants_nothing_on_the_others(string $entity): void
    {
        $user = $this->userGranted($this->permissionsFor($entity));

        foreach (self::entities() as [$other]) {
            if ($other === $entity) {
                continue;
            }

            $record = $this->record($other);

            foreach (self::ABILITIES as $ability) {
                $this->assertFalse(
                    $this->allows($user, $ability, $record),
                    "A role holding every {$entity} permission was allowed {$ability} on {$other}.",
                );
            }
        }
    }

    /**
     * The seeder is what these policies are checked against, so a policy
     * naming a permission nobody ever grants denies silently and for ever.
     */
    #[DataProvider('entities')]
    public function test_every_permission_the_policies_check_exists_as_a_seeded_row(string $entity): void
    {
        foreach ($this->permissionsFor($entity) as $permission) {
            $this->assertTrue(
                Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists(),
                "The policies check {$permission}, which the seeder never creates.",
            );
        }
    }

    /**
     * Advisories have no resource and no permissions of their own; they
     * borrow the package's, which is what keeps them off the Roles screen.
     */
    public function test_reading_advisories_answers_to_the_packages_view_permission(): void
    {
        $advisory = PackageAdvisory::factory()->create();

        $reader = $this->userGranted(['View:Package']);

        $this->assertTrue(Gate::forUser($reader)->allows('viewAny', PackageAdvisory::class));
        $this->assertTrue(Gate::forUser($reader)->allows('view', $advisory));

        // Reading a package says nothing about declaring it vulnerable.
        $this->assertFalse(Gate::forUser($reader)->allows('create', PackageAdvisory::class));
        $this->assertFalse(Gate::forUser($reader)->allows('update', $advisory));
        $this->assertFalse(Gate::forUser($reader)->allows('delete', $advisory));
        $this->assertFalse(Gate::forUser($reader)->allows('deleteAny', PackageAdvisory::class));
    }

    public function test_curating_advisories_answers_to_the_packages_update_permission(): void
    {
        $advisory = PackageAdvisory::factory()->create();

        $curator = $this->userGranted(['View:Package', 'Update:Package']);

        $this->assertTrue(Gate::forUser($curator)->allows('create', PackageAdvisory::class));
        $this->assertTrue(Gate::forUser($curator)->allows('update', $advisory));
        $this->assertTrue(Gate::forUser($curator)->allows('delete', $advisory));
        $this->assertTrue(Gate::forUser($curator)->allows('deleteAny', PackageAdvisory::class));
    }

    /**
     * Unscoped:Package widens which rows a user sees; it must not widen what
     * they may do to the ones they already see.
     */
    public function test_unscoped_access_is_not_an_ability_on_anything(): void
    {
        $user = $this->userGranted(['Unscoped:Package']);

        $this->assertTrue($user->hasUnscopedAccess());

        foreach (self::entities() as [$entity]) {
            $record = $this->record($entity);

            foreach (self::ABILITIES as $ability) {
                $this->assertFalse(
                    $this->allows($user, $ability, $record),
                    "Unscoped:Package was allowed {$ability} on {$entity}.",
                );
            }
        }
    }

    public function test_every_package_permission_still_leaves_a_role_scoped(): void
    {
        // The two questions are independent: administering packages is not a
        // grant to see the ones in repositories nobody gave you.
        $this->assertFalse($this->userGranted($this->permissionsFor('Package'))->hasUnscopedAccess());
    }

    /**
     * Granting a role permissions is the one action that can grant every
     * other, so it answers to Role permissions rather than to seniority.
     */
    public function test_only_the_role_permissions_open_the_roles_screen(): void
    {
        $everything = Permission::query()->pluck('name')->all();

        $this->actingAs($this->userGranted(array_diff($everything, $this->permissionsFor('Role'))));

        $this->get('/admin/shield/roles')->assertForbidden();

        $this->actingAs($this->userGranted(['ViewAny:Role', 'View:Role']));

        $this->get('/admin/shield/roles')->assertOk();
    }

    /**
     * The panel is where these permissions are actually spent, and Filament
     * resolves a page's authorization from the same policy the matrix above
     * asks directly.
     */
    public function test_a_role_that_may_read_packages_cannot_reach_the_edit_screen(): void
    {
        $package = Package::factory()->create();

        $this->actingAs($this->userGranted(['ViewAny:Package', 'View:Package', 'Unscoped:Package']));

        $this->get(PackageResource::getUrl('index'))->assertOk();
        $this->get(PackageResource::getUrl('view', ['record' => $package]))->assertOk();

        $this->get(PackageResource::getUrl('edit', ['record' => $package]))->assertForbidden();
        $this->get(PackageResource::getUrl('create'))->assertForbidden();
    }
}
