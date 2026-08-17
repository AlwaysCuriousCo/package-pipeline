<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Repository;
use App\Models\User;
use App\Notifications\UnserveablePackageNames;
use App\Services\Mirror\MirrorService;
use App\Services\PackageSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Support\Zipball;
use Tests\TestCase;

/**
 * Composer package names are lowercase, and until this was enforced a package
 * whose composer.json declared `Acme/Widgets` was stored exactly that way — and
 * was then unfetchable through its own `/p2` and `/dist` endpoints on every
 * engine whose collation is case-sensitive, which is two of the three this app
 * supports.
 *
 * The suite runs on SQLite, where that comparison *is* case-sensitive, so every
 * lookup asserted here fails on an unnormalized column rather than passing by
 * the accident that hid this on MySQL.
 */
class PackageNameNormalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.dists'));
    }

    /**
     * The endpoints a first sync reads, declaring a mixed-case name.
     */
    private function fakeGitHub(string $declaredName): void
    {
        Http::fake([
            'api.github.com/repos/acme/widgets/tags*' => Http::response([
                ['name' => 'v1.0.0', 'commit' => ['sha' => str_repeat('a', 40)]],
            ]),
            'api.github.com/repos/acme/widgets/branches*' => Http::response([]),
            'api.github.com/repos/acme/widgets/contents/composer.json*' => Http::response([
                'name' => $declaredName,
                'description' => 'Widgets for Acme.',
                'type' => 'library',
            ]),
            'api.github.com/repos/acme/widgets/commits/*' => Http::response([
                'commit' => ['committer' => ['date' => '2026-02-01T12:00:00Z']],
            ]),
            'api.github.com/repos/acme/widgets/zipball/*' => Http::response(Zipball::bytes(), 200, [
                'Content-Type' => 'application/zip',
            ]),
        ]);
    }

    /**
     * A package with one served version, created under whatever name is given.
     */
    private function makeServedPackage(string $name): Package
    {
        $package = Package::factory()->create([
            'name' => $name,
            'repository' => 'https://github.com/acme/widgets',
        ]);

        $package->versions()->create([
            'version' => '1.0.0',
            'order' => '1.0.0.0',
            'reference' => str_repeat('a', 40),
            'is_dev' => false,
            'archive_path' => 'packages/acme/widgets/v100.zip',
            'shasum' => sha1('zip-bytes'),
            'metadata' => ['name' => $package->name, 'version' => '1.0.0'],
        ]);

        return $package;
    }

    public function test_a_name_is_lowercased_when_a_package_is_created(): void
    {
        $package = Package::factory()->create(['name' => 'Acme/Internal']);

        $this->assertSame('acme/internal', $package->name);
        $this->assertSame('acme/internal', $package->fresh()->name);
    }

    public function test_a_name_is_lowercased_when_a_package_is_renamed(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        $package->update(['name' => 'Acme/Gadgets']);

        $this->assertSame('acme/gadgets', $package->fresh()->name);
    }

    /**
     * The bug's entry point: the name a sync adopts comes straight out of a
     * composer.json this registry does not control.
     */
    public function test_a_sync_stores_a_mixed_case_composer_name_in_lowercase(): void
    {
        $this->fakeGitHub('Acme/Widgets');

        $package = Package::factory()->unreleased()->create([
            'name' => 'acme/widgets-placeholder',
            'repository' => 'https://github.com/acme/widgets',
            'token' => 'ghp_secret',
        ]);

        app(PackageSynchronizer::class)->sync($package);

        $this->assertSame('acme/widgets', $package->fresh()->name);
    }

    /**
     * The whole point of the fix: such a package has to be reachable through
     * the endpoint Composer resolves it with.
     */
    public function test_a_package_synced_under_a_mixed_case_name_is_serveable(): void
    {
        $this->fakeGitHub('Acme/Widgets');

        $package = Package::factory()->unreleased()->create([
            'name' => 'acme/widgets-placeholder',
            'repository' => 'https://github.com/acme/widgets',
            'token' => 'ghp_secret',
        ]);

        app(PackageSynchronizer::class)->sync($package);

        $this->get('/p2/acme/widgets.json')
            ->assertOk()
            ->assertJsonPath('packages.acme/widgets.0.version', '1.0.0');
    }

    /**
     * A manifest that only differs in case is the same name, not a rename to
     * refuse — which would otherwise be re-reported on every hourly sync.
     */
    public function test_a_case_only_difference_is_not_reported_as_a_refused_rename(): void
    {
        $this->fakeGitHub('Acme/Widgets');

        $package = Package::factory()->unreleased()->create([
            'name' => 'acme/widgets',
            'repository' => 'https://github.com/acme/widgets',
            'token' => 'ghp_secret',
            'last_synced_at' => now()->subDay(),
        ]);

        app(PackageSynchronizer::class)->sync($package);

        $package->refresh();

        $this->assertSame('acme/widgets', $package->name);
        $this->assertNull($package->sync_error);
    }

    public function test_metadata_resolves_a_name_spelled_in_another_case(): void
    {
        $this->makeServedPackage('acme/widgets');

        $this->get('/p2/Acme/Widgets.json')
            ->assertOk()
            // Answered under the stored spelling, which is the only one the
            // dist URLs in the body can be built from.
            ->assertJsonPath('packages.acme/widgets.0.version', '1.0.0');
    }

    public function test_dist_resolves_a_name_spelled_in_another_case(): void
    {
        $this->makeServedPackage('acme/widgets');

        Storage::disk(config('filesystems.dists'))->put('packages/acme/widgets/v100.zip', 'zip-bytes');

        $this->get('/dist/Acme/Widgets/'.str_repeat('a', 40).'.zip')->assertOk();
    }

    /**
     * The dependency-confusion guard has to agree with the normalization: a
     * name introduced in mixed case is published in lowercase, and an upstream
     * must never be asked about it under either spelling.
     */
    public function test_the_mirror_guard_still_sees_a_published_name(): void
    {
        $this->makeServedPackage('Acme/Widgets');

        $this->assertFalse(
            app(MirrorService::class)
                ->mayMirror(Repository::default(), 'acme/widgets'),
        );
    }

    /**
     * A save that is not about the name must not rewrite it, because on a
     * registry migrated from before this fix the rewrite could collide with a
     * sibling row and fail a sync that had nothing to do with naming.
     */
    public function test_an_unrelated_save_leaves_a_legacy_mixed_case_name_alone(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        // Straight to the column, as a pre-fix registry would hold it.
        DB::table('packages')->where('id', $package->id)->update(['name' => 'Acme/Widgets']);

        $package->refresh()->forceFill(['webhook_received_at' => now()])->save();

        $this->assertSame('Acme/Widgets', $package->fresh()->name);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_10_150000_normalize_package_names.php');
    }

    public function test_the_migration_normalizes_stored_names(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        DB::table('packages')->where('id', $package->id)->update([
            'name' => 'Acme/Widgets',
            'updated_at' => now()->subYear(),
        ]);

        $this->migration()->up();

        $package->refresh();

        $this->assertSame('acme/widgets', $package->name);
        // The name is rendered into the /p2 body and every dist URL in it, and
        // the validators are cut from this column — so a rename that left it
        // alone would serve the old document indefinitely.
        $this->assertTrue($package->updated_at->isToday());
    }

    /**
     * The case the migration must not explode on: two rows differing only in
     * case, which the (repository_id, name) unique index permits on a
     * case-sensitive engine and which cannot both be lowercased.
     */
    public function test_the_migration_leaves_a_colliding_pair_alone_and_flags_it(): void
    {
        $lowercase = Package::factory()->create(['name' => 'acme/widgets']);
        $mixed = Package::factory()->create(['name' => 'acme/widgets-other']);

        DB::table('packages')->where('id', $mixed->id)->update(['name' => 'Acme/Widgets']);

        $this->migration()->up();

        // Neither row is renamed and neither is deleted: which of the two the
        // registry should keep is not a question a migration may answer.
        $this->assertSame('acme/widgets', $lowercase->fresh()->name);
        $this->assertSame('Acme/Widgets', $mixed->fresh()->name);

        $this->assertStringContainsString('cannot be fetched', (string) $mixed->fresh()->sync_error);
        $this->assertNull($lowercase->fresh()->sync_error);
    }

    /**
     * The note the migration leaves is the only thing pointing at a package
     * that 404s on both of its endpoints, and it used to last an hour: a clean
     * sync has nothing to say for itself and says so into the same column.
     */
    public function test_a_clean_sync_re_asserts_the_notice_instead_of_clearing_it(): void
    {
        $this->fakeGitHub('Acme/Widgets');

        $package = Package::factory()->unreleased()->create([
            'name' => 'acme/widgets',
            'repository' => 'https://github.com/acme/widgets',
            'token' => 'ghp_secret',
            'last_synced_at' => now()->subDay(),
        ]);

        // Straight to the column: this is a row the normalization migration had
        // to leave alone, and the model would fold the name on the way in.
        DB::table('packages')->where('id', $package->id)->update(['name' => 'Acme/Widgets']);

        app(PackageSynchronizer::class)->sync($package->refresh());

        $package->refresh();

        $this->assertSame('Acme/Widgets', $package->name);
        $this->assertStringContainsString('cannot be fetched', (string) $package->sync_error);
    }

    /**
     * And the manifest matching that name in every way but its case is not a
     * second problem to report — accepting the "rename" would collide on the
     * unique index, which is why the row is stuck in the first place.
     */
    public function test_a_legacy_mixed_case_row_is_not_also_told_to_accept_a_rename(): void
    {
        $this->fakeGitHub('Acme/Widgets');

        $package = Package::factory()->unreleased()->create([
            'name' => 'acme/widgets',
            'repository' => 'https://github.com/acme/widgets',
            'token' => 'ghp_secret',
            'last_synced_at' => now()->subDay(),
        ]);

        DB::table('packages')->where('id', $package->id)->update(['name' => 'Acme/Widgets']);

        app(PackageSynchronizer::class)->sync($package->refresh());

        $this->assertStringNotContainsString('The name was left alone', (string) $package->fresh()->sync_error);
    }

    /**
     * A deploy running unattended in CI has nobody reading the migration's
     * output, so the finding has to reach the bell and the Slack channel too.
     */
    public function test_the_migration_notifies_the_admins_it_could_not_normalize(): void
    {
        Notification::fake();

        $admin = User::factory()->create();
        $admin->assignRole(Role::create(['name' => 'maintainer', 'guard_name' => 'web']));

        Package::factory()->create(['name' => 'acme/widgets']);
        $mixed = Package::factory()->create(['name' => 'acme/widgets-other']);

        DB::table('packages')->where('id', $mixed->id)->update(['name' => 'Acme/Widgets']);

        $this->migration()->up();

        Notification::assertSentTo(
            $admin,
            fn (UnserveablePackageNames $notification): bool => $notification->names === ['Acme/Widgets'],
        );
    }

    public function test_the_migration_says_nothing_when_every_name_normalized(): void
    {
        Notification::fake();

        Package::factory()->create(['name' => 'acme/widgets']);

        $this->migration()->up();

        Notification::assertNothingSent();
    }

    public function test_the_migration_leaves_already_normalized_rows_untouched(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        DB::table('packages')->where('id', $package->id)->update(['updated_at' => now()->subYear()]);

        $this->migration()->up();

        // Every Composer client in the fleet refetches a package whose
        // updated_at moves, so a no-op row has to stay a no-op.
        $this->assertTrue($package->fresh()->updated_at->isBefore(now()->subMonth()));
    }
}
