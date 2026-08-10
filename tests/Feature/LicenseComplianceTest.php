<?php

namespace Tests\Feature;

use App\Filament\Pages\Licenses;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\Repository;
use App\Models\User;
use App\Services\LicenseReport;
use App\Services\SbomExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Licence reporting and the CycloneDX export.
 *
 * @see docs/licensing.md
 */
class LicenseComplianceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  string|list<string>|null  $license
     */
    private function makeVersion(Package $package, string $version, string|array|null $license): PackageVersion
    {
        return $package->versions()->create([
            'version' => $version,
            'reference' => sha1($package->name.$version),
            'is_dev' => false,
            'shasum' => sha1($version),
            'archive_path' => "packages/{$package->name}/{$version}.zip",
            'released_at' => now(),
            'metadata' => array_filter([
                'name' => $package->name,
                'version' => $version,
                'license' => $license,
            ], fn (mixed $value): bool => $value !== null),
        ]);
    }

    /**
     * A panel user whose role can read packages but is not unscoped.
     */
    private function makeScopedUser(): User
    {
        $role = Role::findOrCreate('developer', 'web');
        $role->givePermissionTo(['ViewAny:Package', 'View:Package']);

        return tap(User::factory()->create())->assignRole($role);
    }

    public function test_a_declared_license_is_stored_beside_the_manifest(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        $this->assertSame('MIT', $this->makeVersion($package, '1.0.0', 'MIT')->license);
    }

    /**
     * Composer's semantics for several licences is a choice between them,
     * which is what an SPDX "OR" expression means.
     */
    public function test_several_declared_licenses_fold_to_one_expression(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        $version = $this->makeVersion($package, '1.0.0', ['MIT', 'Apache-2.0']);

        $this->assertSame('MIT OR Apache-2.0', $version->license);
    }

    public function test_a_version_declaring_nothing_stores_no_license(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);

        $this->assertNull($this->makeVersion($package, '1.0.0', null)->license);
    }

    /**
     * The synchronizer reads a version back with a subset of its columns on
     * every sync and saves it again to record the archive. Recomputing the
     * licence from a `metadata` that was never selected would clear it.
     */
    public function test_a_save_that_does_not_touch_the_manifest_keeps_the_license(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);
        $version = $this->makeVersion($package, '1.0.0', 'MIT');

        $partial = PackageVersion::query()
            ->select(['id', 'package_id', 'version', 'archive_path'])
            ->findOrFail($version->getKey());

        $partial->forceFill(['archive_path' => 'packages/acme/widgets/new.zip'])->save();

        $this->assertSame('MIT', $version->refresh()->license);
    }

    public function test_the_report_counts_packages_and_versions_per_license(): void
    {
        $widgets = Package::factory()->create(['name' => 'acme/widgets']);
        $gadgets = Package::factory()->create(['name' => 'acme/gadgets']);

        $this->makeVersion($widgets, '1.0.0', 'MIT');
        $this->makeVersion($widgets, '1.1.0', 'MIT');
        $this->makeVersion($gadgets, '1.0.0', 'MIT');
        $this->makeVersion($gadgets, '2.0.0', null);

        $summary = (new LicenseReport)->summary();

        $this->assertSame('MIT', $summary->first()->license);
        $this->assertSame(2, $summary->first()->packages);
        $this->assertSame(3, $summary->first()->versions);

        $undeclared = $summary->firstWhere('license', null);

        $this->assertSame(1, $undeclared->versions);
        $this->assertSame('Not declared', $undeclared->label());

        $this->assertSame(
            ['versions' => 4, 'packages' => 2, 'undeclared' => 1],
            (new LicenseReport)->totals(),
        );
    }

    public function test_the_report_only_counts_what_the_user_may_see(): void
    {
        $private = Repository::factory()->create(['path' => 'internal', 'public' => false]);

        $open = Package::factory()->create(['name' => 'acme/open']);
        $secret = Package::factory()->create(['name' => 'acme/secret', 'repository_id' => $private->id]);

        $this->makeVersion($open, '1.0.0', 'MIT');
        $this->makeVersion($secret, '1.0.0', 'GPL-3.0-only');

        $report = new LicenseReport($this->makeScopedUser());

        $this->assertSame(['MIT' => 'MIT'], $report->options());
        $this->assertSame(1, $report->totals()['versions']);
    }

    public function test_the_licenses_page_lists_the_visible_versions_only(): void
    {
        $private = Repository::factory()->create(['path' => 'internal', 'public' => false]);

        $open = Package::factory()->create(['name' => 'acme/open']);
        $secret = Package::factory()->create(['name' => 'acme/secret', 'repository_id' => $private->id]);

        $visible = $this->makeVersion($open, '1.0.0', 'MIT');
        $hidden = $this->makeVersion($secret, '1.0.0', 'GPL-3.0-only');

        $this->actingAs($this->makeScopedUser());

        Livewire::test(Licenses::class)
            ->assertCanSeeTableRecords([$visible])
            ->assertCanNotSeeTableRecords([$hidden]);
    }

    public function test_the_licenses_page_is_closed_to_an_account_that_cannot_read_packages(): void
    {
        $this->actingAs(tap(User::factory()->create())->assignRole(Role::findOrCreate('nobody', 'web')));

        $this->assertFalse(Licenses::canAccess());
    }

    /**
     * The whole document, decoded — the shape is the contract, and a
     * hand-streamed one has to be proved well-formed rather than assumed.
     *
     * @return array<string, mixed>
     */
    private function export(SbomExport $export): array
    {
        $json = '';

        foreach ($export->chunks() as $chunk) {
            $json .= $chunk;
        }

        $decoded = json_decode($json, true);

        $this->assertIsArray($decoded, "The export is not valid JSON:\n{$json}");

        return $decoded;
    }

    public function test_the_sbom_envelope_matches_the_cyclonedx_schema(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);
        $this->makeVersion($package, '1.0.0', 'MIT');

        $document = $this->export(new SbomExport);

        $this->assertSame('CycloneDX', $document['bomFormat']);
        $this->assertSame('1.6', $document['specVersion']);
        $this->assertSame('http://cyclonedx.org/schema/bom-1.6.schema.json', $document['$schema']);
        $this->assertSame(1, $document['version']);

        // The schema's pattern for this admits lowercase hex only.
        $this->assertMatchesRegularExpression(
            '/^urn:uuid:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $document['serialNumber'],
        );

        // The array-of-tools form was deprecated in 1.5; the object carrying
        // `components` is what replaced it.
        $this->assertArrayHasKey('components', $document['metadata']['tools']);
        $this->assertSame('application', $document['metadata']['tools']['components'][0]['type']);
        $this->assertNotEmpty($document['metadata']['timestamp']);
    }

    public function test_a_component_describes_the_version_it_serves(): void
    {
        $package = Package::factory()->create([
            'name' => 'acme/widgets',
            'description' => 'Widgets for Acme.',
            'type' => 'library',
            'repository' => 'https://github.com/acme/widgets',
        ]);

        $version = $this->makeVersion($package, '1.0.0', 'MIT');

        $component = $this->export(new SbomExport)['components'][0];

        $this->assertSame('library', $component['type']);
        $this->assertSame('acme', $component['group']);
        $this->assertSame('widgets', $component['name']);
        $this->assertSame('1.0.0', $component['version']);
        $this->assertSame('Widgets for Acme.', $component['description']);

        // The registry the package actually comes from, so nothing resolves
        // this purl against packagist.org.
        $this->assertStringStartsWith('pkg:composer/acme/widgets@1.0.0?repository_url=', $component['purl']);
        $this->assertSame($component['purl'], $component['bom-ref']);

        // The archive's hash belongs to the distribution, not to the component
        // — it is the hash of a zip, not of the package as a thing.
        $distribution = $this->reference($component['externalReferences'], 'distribution');

        $this->assertSame('SHA-1', $distribution['hashes'][0]['alg']);
        $this->assertSame($version->shasum, $distribution['hashes'][0]['content']);
        $this->assertStringContainsString('/dist/acme/widgets/', $distribution['url']);

        $this->assertSame(
            'https://github.com/acme/widgets',
            $this->reference($component['externalReferences'], 'vcs')['url'],
        );
    }

    /**
     * `id` is a closed SPDX enumeration in the schema, so one mistyped licence
     * anywhere would fail the whole document; `expression` is a plain string.
     */
    public function test_licenses_are_emitted_as_a_single_spdx_expression(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);
        $this->makeVersion($package, '1.0.0', ['MIT', 'Apache-2.0']);

        $licenses = $this->export(new SbomExport)['components'][0]['licenses'];

        $this->assertSame([['expression' => 'MIT OR Apache-2.0']], $licenses);
    }

    /**
     * Except for the one value Composer documents that SPDX does not define.
     */
    public function test_a_proprietary_license_is_emitted_as_a_named_one(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);
        $this->makeVersion($package, '1.0.0', 'proprietary');

        $licenses = $this->export(new SbomExport)['components'][0]['licenses'];

        $this->assertSame([['license' => ['name' => 'proprietary']]], $licenses);
    }

    /**
     * An empty `licenses` array reads as an assertion that the component has
     * none, where the truth is that nobody said.
     */
    public function test_a_version_declaring_no_license_carries_no_licenses_key(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);
        $this->makeVersion($package, '1.0.0', null);

        $this->assertArrayNotHasKey('licenses', $this->export(new SbomExport)['components'][0]);
    }

    public function test_a_registry_wide_sbom_carries_every_visible_version(): void
    {
        $widgets = Package::factory()->create(['name' => 'acme/widgets']);
        $gadgets = Package::factory()->create(['name' => 'acme/gadgets']);

        $this->makeVersion($widgets, '1.0.0', 'MIT');
        $this->makeVersion($widgets, '1.1.0', 'MIT');
        $this->makeVersion($gadgets, '1.0.0', 'MIT');

        $document = $this->export(new SbomExport);

        $this->assertCount(3, $document['components']);
        $this->assertSame('application', $document['metadata']['component']['type']);
    }

    public function test_a_per_package_sbom_carries_only_that_package(): void
    {
        $widgets = Package::factory()->create(['name' => 'acme/widgets']);
        $gadgets = Package::factory()->create(['name' => 'acme/gadgets']);

        $this->makeVersion($widgets, '1.0.0', 'MIT');
        $this->makeVersion($gadgets, '1.0.0', 'MIT');

        $export = new SbomExport(package: $widgets);
        $document = $this->export($export);

        $this->assertCount(1, $document['components']);
        $this->assertSame('widgets', $document['components'][0]['name']);
        $this->assertSame('widgets', $document['metadata']['component']['name']);
        $this->assertSame('sbom-acme-widgets.cdx.json', $export->filename());
        $this->assertSame(1, $export->count());
    }

    public function test_an_sbom_never_names_a_package_the_user_cannot_see(): void
    {
        $private = Repository::factory()->create(['path' => 'internal', 'public' => false]);

        $open = Package::factory()->create(['name' => 'acme/open']);
        $secret = Package::factory()->create(['name' => 'acme/secret', 'repository_id' => $private->id]);

        $this->makeVersion($open, '1.0.0', 'MIT');
        $this->makeVersion($secret, '1.0.0', 'MIT');

        $document = $this->export(new SbomExport(user: $this->makeScopedUser()));

        $this->assertSame(['open'], array_column($document['components'], 'name'));
    }

    public function test_the_export_route_streams_the_document(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);
        $this->makeVersion($package, '1.0.0', 'MIT');

        $this->actingAs($this->makeScopedUser());

        $response = $this->get(route('exports.sbom'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.cyclonedx+json; version=1.6');
        $response->assertDownload('sbom-registry.cdx.json');

        $document = json_decode($response->streamedContent(), true);

        $this->assertSame('CycloneDX', $document['bomFormat']);
        $this->assertCount(1, $document['components']);
    }

    public function test_the_export_route_is_closed_to_an_account_that_cannot_read_packages(): void
    {
        $this->actingAs(tap(User::factory()->create())->assignRole(Role::findOrCreate('nobody', 'web')));

        $this->get(route('exports.sbom'))->assertForbidden();
    }

    /**
     * An invisible package and a missing one read alike, or the 404 itself
     * would tell a scoped user which ids exist.
     */
    public function test_the_export_route_will_not_narrow_to_an_invisible_package(): void
    {
        $private = Repository::factory()->create(['path' => 'internal', 'public' => false]);
        $secret = Package::factory()->create(['name' => 'acme/secret', 'repository_id' => $private->id]);

        $this->actingAs($this->makeScopedUser());

        $this->get(route('exports.sbom', ['package' => $secret->getKey()]))->assertNotFound();
    }

    public function test_the_command_writes_a_document_to_a_file(): void
    {
        $package = Package::factory()->create(['name' => 'acme/widgets']);
        $this->makeVersion($package, '1.0.0', 'MIT');

        $path = tempnam(sys_get_temp_dir(), 'sbom-test-');

        $this->artisan('sbom:export', ['--path' => $path])
            ->expectsOutputToContain('Wrote 1 components')
            ->assertSuccessful();

        $document = json_decode((string) file_get_contents($path), true);

        $this->assertSame('CycloneDX', $document['bomFormat']);
        $this->assertSame('acme', $document['components'][0]['group']);
    }

    /**
     * One of a component's external references, by type.
     *
     * @return array<mixed, mixed>
     */
    private function reference(mixed $references, string $type): array
    {
        foreach ((array) $references as $reference) {
            if (is_array($reference) && ($reference['type'] ?? null) === $type) {
                return $reference;
            }
        }

        $this->fail("The component carries no \"{$type}\" external reference.");
    }

    public function test_the_command_refuses_a_package_name_it_cannot_resolve(): void
    {
        $this->artisan('sbom:export', ['--package' => 'acme/nothing'])->assertFailed();
    }
}
