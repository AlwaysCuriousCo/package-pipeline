<?php

namespace Tests\Feature;

use App\Filament\Resources\Packages\Pages\ListPackages;
use App\Models\Download;
use App\Models\Package;
use App\Models\Repository;
use App\Models\User;
use App\Services\DownloadExport;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Exporting download statistics: what comes out, and — the part that matters —
 * who is allowed to see it.
 *
 * @see docs/download-analytics.md
 */
class DownloadExportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A panel user whose role can read packages but is not unscoped — the
     * shape of an external collaborator's account, and the only shape in which
     * "may open the page, may not see this package" is a real distinction.
     */
    private function scopedUser(): User
    {
        $role = Role::findOrCreate('developer', 'web');
        $role->givePermissionTo(['ViewAny:Package', 'View:Package']);

        return tap(User::factory()->create())->assignRole($role);
    }

    private function record(Package $package, string $version, string $when, ?string $tokenPrefix = 'pp_abcd'): Download
    {
        return Download::query()->create([
            'package_id' => $package->getKey(),
            'version' => $version,
            'token_prefix' => $tokenPrefix,
            'created_at' => $when,
        ]);
    }

    /**
     * The CSV as parsed rows, so a test asserts about the file an operator
     * actually opens rather than about the generator behind it.
     *
     * @return list<list<string>>
     */
    private function csv(DownloadExport $export): array
    {
        $handle = fopen('php://memory', 'r+');

        $export->writeTo($handle);

        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    public function test_the_summary_counts_downloads_per_version(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        $this->record($package, '1.0.0', '2026-08-01 10:00:00');
        $this->record($package, '1.0.0', '2026-08-02 10:00:00');
        $this->record($package, '1.1.0', '2026-08-03 10:00:00');

        $rows = $this->csv(new DownloadExport);

        $this->assertSame(
            ['package', 'repository', 'version', 'downloads', 'first_download', 'last_download'],
            $rows[0],
        );

        $this->assertSame('acme/widgets', $rows[1][0]);
        $this->assertSame('1.0.0', $rows[1][2]);
        $this->assertSame('2', $rows[1][3]);
        $this->assertSame('1.1.0', $rows[2][2]);
        $this->assertSame('1', $rows[2][3]);

        // The default repository is served at the root and has no path.
        $this->assertSame('(root)', $rows[1][1]);
    }

    public function test_the_detail_writes_one_row_per_download(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        $this->record($package, '1.0.0', '2026-08-01 10:00:00');
        $this->record($package, '1.0.0', '2026-08-02 10:00:00', tokenPrefix: null);

        $rows = $this->csv(new DownloadExport(report: DownloadExport::DETAIL));

        $this->assertSame(
            ['downloaded_at', 'package', 'repository', 'version', 'token_prefix'],
            $rows[0],
        );
        $this->assertCount(3, $rows);
        $this->assertSame('pp_abcd', $rows[1][4]);
        // A public repository serves anonymous downloads; no credential is a
        // fact rather than a gap.
        $this->assertSame('(anonymous)', $rows[2][4]);
    }

    /**
     * Both ends as an operator means them: "up to the 31st" includes the 31st.
     */
    public function test_the_date_range_is_inclusive_of_both_days(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        $this->record($package, '1.0.0', '2026-07-31 23:59:00');
        $this->record($package, '1.0.0', '2026-08-01 00:01:00');
        $this->record($package, '1.0.0', '2026-08-03 23:30:00');
        $this->record($package, '1.0.0', '2026-08-04 00:30:00');

        $rows = $this->csv(new DownloadExport(
            report: DownloadExport::DETAIL,
            from: now()->parse('2026-08-01')->toImmutable(),
            to: now()->parse('2026-08-03')->toImmutable(),
        ));

        $this->assertCount(3, $rows, 'The header plus the two downloads inside the window.');
    }

    public function test_an_export_can_be_narrowed_to_one_package(): void
    {
        $widgets = Package::factory()->create(['name' => 'acme/widgets']);
        $gadgets = Package::factory()->create(['name' => 'acme/gadgets']);

        $this->record($widgets, '1.0.0', '2026-08-01 10:00:00');
        $this->record($gadgets, '2.0.0', '2026-08-01 10:00:00');

        $rows = $this->csv(new DownloadExport(package: $widgets));

        $this->assertCount(2, $rows);
        $this->assertSame('acme/widgets', $rows[1][0]);
    }

    /**
     * The one thing an export must not do: hand a scoped admin the history of
     * packages they cannot see.
     */
    public function test_an_export_is_scoped_to_what_the_user_may_see(): void
    {
        $private = Repository::factory()->create(['path' => 'internal', 'public' => false]);

        $public = Package::factory()->create(['name' => 'acme/public']);
        $hidden = Package::factory()->create(['name' => 'acme/hidden', 'repository_id' => $private->getKey()]);

        $this->record($public, '1.0.0', '2026-08-01 10:00:00');
        $this->record($hidden, '1.0.0', '2026-08-01 10:00:00');

        $rows = $this->csv(new DownloadExport(user: $this->scopedUser()));

        $this->assertCount(2, $rows);
        $this->assertSame('acme/public', $rows[1][0]);
    }

    public function test_an_unscoped_admin_sees_the_whole_registry(): void
    {
        $private = Repository::factory()->create(['path' => 'internal', 'public' => false]);

        $hidden = Package::factory()->create(['name' => 'acme/hidden', 'repository_id' => $private->getKey()]);

        $this->record($hidden, '1.0.0', '2026-08-01 10:00:00');

        $rows = $this->csv(new DownloadExport(user: User::factory()->superAdmin()->create()));

        $this->assertCount(2, $rows);
        $this->assertSame('acme/hidden', $rows[1][0]);
    }

    public function test_the_filename_carries_the_report_the_scope_and_the_window(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        $this->assertSame(
            'downloads-detail-acme-widgets-2026-08-01_2026-08-31.csv',
            (new DownloadExport(
                report: DownloadExport::DETAIL,
                from: now()->parse('2026-08-01')->toImmutable(),
                to: now()->parse('2026-08-31')->toImmutable(),
                package: $package,
            ))->filename(),
        );

        $this->assertSame('downloads-summary-registry-all-time.csv', (new DownloadExport)->filename());
    }

    public function test_the_route_streams_a_csv_to_a_signed_in_admin(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        $this->record($package, '1.0.0', '2026-08-01 10:00:00');

        $response = $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('exports.downloads'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            // A report about who downloaded what, cut to one admin's grants.
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertStringContainsString('acme/widgets', $response->streamedContent());
    }

    public function test_the_route_refuses_an_anonymous_caller(): void
    {
        $this->get(route('exports.downloads'))->assertRedirect();
    }

    /**
     * A package the caller cannot see must 404 rather than quietly widening
     * the export to the whole registry.
     */
    public function test_the_route_refuses_a_package_the_caller_cannot_see(): void
    {
        $private = Repository::factory()->create(['path' => 'internal', 'public' => false]);
        $hidden = Package::factory()->create(['repository_id' => $private->getKey()]);

        $this->actingAs($this->scopedUser())
            ->get(route('exports.downloads', ['package' => $hidden->getKey()]))
            ->assertNotFound();
    }

    public function test_the_route_rejects_a_range_that_ends_before_it_starts(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('exports.downloads', ['from' => '2026-08-31', 'to' => '2026-08-01']))
            ->assertSessionHasErrors('to');
    }

    public function test_the_panel_action_opens_the_export_route(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(ListPackages::class)
            ->callAction(TestAction::make('exportDownloads'), [
                'report' => DownloadExport::DETAIL,
                'from' => '2026-08-01',
            ])
            ->assertRedirect(route('exports.downloads', [
                'report' => DownloadExport::DETAIL,
                'from' => '2026-08-01',
            ]));
    }

    public function test_the_command_writes_a_summary_to_a_file(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        $this->record($package, '1.0.0', '2026-08-01 10:00:00');
        $this->record($package, '1.0.0', '2026-08-02 10:00:00');

        $path = storage_path('framework/testing/downloads.csv');

        $this->artisan('downloads:export', ['--path' => $path])->assertSuccessful();

        $contents = (string) File::get($path);

        $this->assertStringContainsString('package,repository,version,downloads', $contents);
        $this->assertStringContainsString('acme/widgets,(root),1.0.0,2', $contents);

        File::delete($path);
    }

    public function test_the_command_refuses_a_name_nothing_publishes(): void
    {
        $this->artisan('downloads:export', ['--package' => 'acme/nope'])
            ->expectsOutputToContain('No package is named "acme/nope"')
            ->assertFailed();
    }

    /**
     * A name is only unique within a Composer repository, and guessing between
     * two would silently export the wrong package's history.
     */
    public function test_the_command_refuses_an_ambiguous_name(): void
    {
        $other = Repository::factory()->create(['path' => 'internal']);

        Package::factory()->create(['name' => 'acme/widgets']);
        Package::factory()->create(['name' => 'acme/widgets', 'repository_id' => $other->getKey()]);

        $this->artisan('downloads:export', ['--package' => 'acme/widgets'])
            ->expectsOutputToContain('more than one Composer repository')
            ->assertFailed();

        $path = storage_path('framework/testing/ambiguous.csv');

        $this->artisan('downloads:export', ['--package' => 'acme/widgets', '--repository' => 'internal', '--path' => $path])
            ->assertSuccessful();

        File::delete($path);
    }

    public function test_the_command_refuses_a_date_it_cannot_read(): void
    {
        $this->artisan('downloads:export', ['--from' => 'last tuesday-ish'])
            ->expectsOutputToContain('is not a date this can read')
            ->assertFailed();
    }
}
