<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Filament\Resources\Activities\ActivityResource;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Models\Activity;
use App\Models\AuthenticationSource;
use App\Models\DeployToken;
use App\Models\Package;
use App\Models\PackageAdvisory;
use App\Models\Repository;
use App\Models\Source;
use App\Models\Team;
use App\Models\Token;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The forensic trail. Token rolls, grants and package deletions are the
 * changes somebody asks about months later, and none of them left a trace
 * before this existed.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->superAdmin()->create();
        $this->actingAs($this->admin);
    }

    /**
     * @return array<int, Activity>
     */
    private function entriesFor(string $subjectType): array
    {
        return Activity::query()
            ->where('subject_type', $subjectType)
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function test_package_lifecycle_changes_are_recorded(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);
        $package->update(['name' => 'acme/renamed']);
        $package->delete();

        $events = array_map(fn (Activity $entry): ?string => $entry->event, $this->entriesFor(Package::class));

        $this->assertSame(['created', 'updated', 'deleted'], $events);
    }

    public function test_a_change_is_attributed_to_the_signed_in_admin(): void
    {
        Package::factory()->create();

        $entry = Activity::query()->where('subject_type', Package::class)->sole();

        $this->assertTrue($entry->causer->is($this->admin));
    }

    public function test_a_sync_that_only_moves_bookkeeping_records_nothing(): void
    {
        $package = Package::factory()->create();

        Activity::query()->delete();

        // What the hourly sync writes on a package with no new refs. Filing a
        // row for this would bury every change that matters under one per
        // package per hour.
        $package->update([
            'last_synced_at' => now(),
            'sync_error' => null,
            'total_downloads' => 12,
        ]);

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_issuing_and_rolling_a_token_is_recorded_without_the_token(): void
    {
        $deployToken = DeployToken::factory()->create(['name' => 'production-deploys']);

        $new = Token::issue($deployToken, $deployToken->name, [TokenAbility::RepositoryRead]);

        // A roll, exactly as the panel performs it: the old credential dies,
        // a new one is issued.
        $deployToken->tokens->each->delete();
        Token::issue($deployToken, $deployToken->name, [TokenAbility::RepositoryRead]);

        $events = array_map(fn (Activity $entry): ?string => $entry->event, $this->entriesFor(Token::class));

        $this->assertSame(['created', 'deleted', 'created'], $events);

        $properties = Activity::query()->where('subject_type', Token::class)->get()
            ->map(fn (Activity $entry): string => (string) json_encode($entry->properties))
            ->implode(' ');

        // Neither the plain token nor the sha256 the row stores.
        $this->assertStringNotContainsString($new->plainText, $properties);
        $this->assertStringNotContainsString(hash('sha256', $new->plainText), $properties);
        // The prefix is logged, because that is how a listing names a token.
        $this->assertStringContainsString($new->token->token_prefix, $properties);
    }

    public function test_a_users_role_changes_are_recorded(): void
    {
        $user = User::factory()->create();
        $role = Role::findOrCreate('panel_user', 'web');

        $user->assignRole($role);
        $user->removeRole($role);

        $entries = Activity::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $user->getKey())
            ->whereIn('event', ['role_granted', 'role_revoked'])
            ->orderBy('id')
            ->get();

        $this->assertSame(['role_granted', 'role_revoked'], $entries->pluck('event')->all());
        // Names, not ids: a role id means nothing once the role is renamed.
        $this->assertSame(['panel_user'], $entries->first()->properties->get('roles'));
    }

    /**
     * The scenario the trait's own docblock promises and did not deliver: an
     * account is added to a team that holds the internal repository, and taken
     * out again once it has read everything. Both writes are pivot rows, which
     * the attribute diff cannot see.
     */
    public function test_team_membership_is_recorded_from_either_side(): void
    {
        $team = Team::factory()->create(['name' => 'platform']);
        $user = User::factory()->create(['name' => 'contractor']);

        Activity::query()->delete();

        $team->users()->attach($user);
        $user->teams()->detach($team);

        $entries = Activity::query()->orderBy('id')->get();

        $this->assertSame(['grant_added', 'grant_removed'], $entries->pluck('event')->all());

        // Recorded against whichever side the change was made from, named as
        // the panel names the relation.
        $this->assertTrue($entries[0]->subject->is($team));
        $this->assertSame('users', $entries[0]->properties->get('grant'));
        $this->assertSame(['contractor'], $entries[0]->properties->get('records'));

        $this->assertTrue($entries[1]->subject->is($user));
        $this->assertSame('teams', $entries[1]->properties->get('grant'));
        $this->assertSame(['platform'], $entries[1]->properties->get('records'));
    }

    /**
     * Filament's relationship selects sync rather than attach, and Laravel
     * fires no pivot event for either — so this is the path that actually
     * matters, and the one a listener would silently have missed.
     */
    public function test_a_synced_grant_is_recorded(): void
    {
        $team = Team::factory()->create();
        $internal = Repository::factory()->create(['name' => 'internal']);
        $public = Repository::factory()->create(['name' => 'public']);

        $team->repositories()->sync([$internal->getKey()]);

        Activity::query()->delete();

        // What saving the form with one box unticked and another ticked does.
        $team->repositories()->sync([$public->getKey()]);

        $entries = Activity::query()->orderBy('id')->get();

        $this->assertSame(['grant_removed', 'grant_added'], $entries->pluck('event')->all());
        $this->assertSame(['internal'], $entries[0]->properties->get('records'));
        $this->assertSame(['public'], $entries[1]->properties->get('records'));
    }

    public function test_a_deploy_tokens_grants_are_recorded(): void
    {
        $deployToken = DeployToken::factory()->create();
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        $deployToken->packages()->attach($package);

        Activity::query()->delete();

        // Losing its last grant is what makes a deploy token unscoped, so the
        // widening change is the removal.
        $deployToken->packages()->detach();

        $entry = Activity::query()->sole();

        $this->assertSame('grant_removed', $entry->event);
        $this->assertSame(['acme/widgets'], $entry->properties->get('records'));
    }

    public function test_a_sync_that_changes_no_grant_records_nothing(): void
    {
        $team = Team::factory()->create();
        $package = Package::factory()->create();

        $team->packages()->sync([$package->getKey()]);

        Activity::query()->delete();

        // Every form save re-syncs; only the ones that move a row are changes.
        $team->packages()->sync([$package->getKey()]);

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_connecting_and_disconnecting_a_source_is_recorded(): void
    {
        $source = Source::factory()->create();
        $source->forceFill(['connected_at' => null])->save();

        Activity::query()->delete();

        // `connected_at` is not fillable — a connection is something the app
        // records, never something a form writes — so this is the path the
        // connect and disconnect flows themselves take.
        $source->forceFill(['connected_at' => now(), 'token' => 'ghp_a_real_secret'])->save();
        $source->disconnect();

        $entries = $this->entriesFor(Source::class);

        $this->assertCount(2, $entries);

        foreach ($entries as $entry) {
            $this->assertArrayHasKey('connected_at', $entry->properties->get('attributes'));
        }
    }

    /**
     * Spatie attributes a change to `auth()->user()`, which is nobody on a
     * token-authenticated surface — so before the middleware set a causer,
     * everything done through /api/v1 was filed as "System".
     */
    public function test_a_change_made_through_a_personal_token_is_attributed_to_its_owner(): void
    {
        $owner = User::factory()->superAdmin()->create(['name' => 'release-engineer']);
        $issued = Token::issue($owner, 'ci-provisioning', [TokenAbility::ApiWrite, TokenAbility::ApiDelete]);

        $package = Package::factory()->create();

        Activity::query()->delete();

        $this->withToken($issued->plainText)
            ->deleteJson("/api/v1/packages/{$package->getKey()}")
            ->assertNoContent();

        $entry = Activity::query()->where('event', 'deleted')->sole();

        $this->assertTrue($entry->causer->is($owner));
        // The person and the person's CI token are the same account and very
        // different news, so the entry names both.
        $this->assertSame("ci-provisioning ({$issued->token->token_prefix})", $entry->properties->get('credential'));
    }

    /**
     * A deploy token has no person behind it. It is its own principal
     * everywhere else in the app, so it is its own causer here rather than
     * being attributed to whoever happened to create it.
     */
    public function test_a_change_made_through_a_deploy_token_is_attributed_to_the_deploy_token(): void
    {
        $deployToken = DeployToken::factory()->create(['name' => 'production-deploys']);
        $issued = Token::issue($deployToken, 'production-deploys', [TokenAbility::ApiWrite, TokenAbility::ApiDelete]);

        $package = Package::factory()->create();

        Activity::query()->delete();

        $this->withToken($issued->plainText)
            ->deleteJson("/api/v1/packages/{$package->getKey()}")
            ->assertNoContent();

        $entry = Activity::query()->where('event', 'deleted')->sole();

        $this->assertTrue($entry->causer->is($deployToken));
    }

    /**
     * The rule the whole trait exists for. An attribute diff is written to a
     * table whose purpose is to be read later, by more people, for longer —
     * so a credential landing in one is a credential leaked.
     */
    public function test_no_secret_ever_reaches_the_log(): void
    {
        $package = Package::factory()->create(['token' => 'ghp_package_secret']);
        $package->update(['token' => 'ghp_rotated_secret', 'webhook_secret' => 'whsec_package']);

        Source::factory()->create(['token' => 'ghp_source_secret']);

        AuthenticationSource::factory()->create(['client_secret' => 'oidc_client_secret']);

        $invited = User::factory()->create(['password' => 'a-very-secret-password']);

        DeployToken::factory()->create();

        $everythingLogged = Activity::query()->get()
            ->map(fn (Activity $entry): string => (string) json_encode($entry->properties))
            ->implode(' ');

        foreach ([
            'ghp_package_secret',
            'ghp_rotated_secret',
            'whsec_package',
            'ghp_source_secret',
            'oidc_client_secret',
            'a-very-secret-password',
            $invited->password,
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $everythingLogged);
        }

        // Nor may the attribute names appear at all — an entry naming
        // `token` with a null beside it is one refactor away from naming it
        // with a value beside it.
        foreach (['"token"', '"password"', '"client_secret"', '"webhook_secret"'] as $attribute) {
            $this->assertStringNotContainsString($attribute, $everythingLogged);
        }
    }

    public function test_repositories_and_advisories_are_recorded(): void
    {
        $repository = Repository::factory()->create();
        $repository->delete();

        PackageAdvisory::factory()->create(['package_id' => Package::factory()->create()->id]);

        $this->assertCount(2, $this->entriesFor(Repository::class));
        $this->assertCount(1, $this->entriesFor(PackageAdvisory::class));
    }

    public function test_the_audit_log_is_readable_in_the_panel(): void
    {
        Package::factory()->create(['name' => 'acme/widgets']);

        $entry = Activity::query()->where('subject_type', Package::class)->sole();

        $this->get('/admin/activities')->assertOk();

        Livewire::test(ListActivities::class)
            ->assertCanSeeTableRecords([$entry])
            ->mountAction(TestAction::make('view')->table($entry))
            ->assertActionMounted(TestAction::make('view')->table($entry));
    }

    public function test_the_audit_log_cannot_be_written_to_through_the_panel(): void
    {
        // Not "permission not held" — refused outright, because an entry
        // somebody could add or remove is an entry nobody could trust.
        $entry = Activity::query()->firstOrFail();

        $this->assertFalse(ActivityResource::canCreate());
        $this->assertFalse($this->admin->can('delete', $entry));
        $this->assertFalse($this->admin->can('update', $entry));

        $this->assertArrayNotHasKey('create', ActivityResource::getPages());
        $this->assertArrayNotHasKey('edit', ActivityResource::getPages());
    }

    public function test_a_role_without_the_permission_cannot_read_the_audit_log(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('observer', 'web'));
        $user->givePermissionTo('ViewAny:Package');

        $this->actingAs($user);

        $this->get('/admin/activities')->assertForbidden();
    }

    public function test_entries_are_pruned_once_they_are_too_old_to_be_asked_about(): void
    {
        Package::factory()->create();

        DB::table('activity_log')->update(['created_at' => now()->subDays(731)]);

        $kept = Package::factory()->create();

        $this->artisan('model:prune', ['--model' => [Activity::class]])->assertSuccessful();

        $this->assertSame(
            [(string) $kept->getKey()],
            Activity::query()->pluck('subject_id')->map(strval(...))->all(),
        );
    }
}
