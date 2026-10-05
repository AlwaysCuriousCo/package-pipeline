<?php

namespace Tests\Feature;

use App\Enums\Ecosystem;
use App\Filament\Resources\Packages\Pages\ListPackages;
use App\Filament\Resources\Packages\Pages\ViewPackage;
use App\Filament\Resources\Packages\RelationManagers\VersionsRelationManager;
use App\Models\Package;
use App\Models\PackageVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Plumb scores: what `plumb:refresh` asks, what it keeps, and what it must
 * leave alone while doing it.
 */
class PlumbScoresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['registry.plumb.enabled' => true]);

        Sleep::fake();
    }

    /**
     * One entry of Plumb's scan history, as its API spells it.
     *
     * @return array<string, mixed>
     */
    private function scan(string $tag, int $composite): array
    {
        return [
            'id' => fake()->uuid(),
            'scanned_at' => '2026-10-03T20:30:21+00:00',
            'reference_version' => $tag,
            'scores' => ['composite' => $composite, 'security' => 100, 'maintenance' => 80, 'ecosystem' => null],
        ];
    }

    public function test_scores_land_on_the_package_and_on_each_release_plumb_scanned(): void
    {
        Http::fake([
            // Newest first, and 1.0.0 scanned twice: the newer scan stands.
            'plumbphp.dev/api/v1/packages/acme/widgets/history*' => Http::response(['data' => [
                $this->scan('v2.0.0', 91),
                $this->scan('v1.0.0', 70),
                $this->scan('v1.0.0', 40),
            ]]),
            'plumbphp.dev/*' => Http::response(['error' => 'Package not found.'], 404),
        ]);

        $package = Package::factory()->create(['name' => 'acme/widgets']);
        $current = PackageVersion::factory()->for($package)->create(['version' => '2.0.0']);
        $previous = PackageVersion::factory()->for($package)->create(['version' => '1.0.0']);
        $unscanned = PackageVersion::factory()->for($package)->create(['version' => '0.9.0']);

        // A private package Plumb has never heard of, still carrying a score
        // from when it had.
        $unknown = Package::factory()->create(['name' => 'acme/private', 'plumb' => ['scores' => ['composite' => 12]]]);

        Package::factory()->create(['name' => '@acme/ui', 'ecosystem' => Ecosystem::Npm]);

        $this->travel(1)->hours();

        $this->artisan('plumb:refresh')->assertSuccessful();

        $this->assertSame(91, $package->fresh()->plumb['scores']['composite']);
        $this->assertSame('v2.0.0', $package->fresh()->plumb['version']);
        $this->assertSame(91, $current->fresh()->plumb['scores']['composite']);
        $this->assertSame(70, $previous->fresh()->plumb['scores']['composite']);
        $this->assertNull($previous->fresh()->plumb['scores']['ecosystem']);
        $this->assertNull($unscanned->fresh()->plumb);
        $this->assertNull($unknown->fresh()->plumb);

        // A score is not something the package publishes: neither timestamp
        // /p2 is fingerprinted from may move because one arrived.
        $this->assertEquals($package->updated_at, $package->fresh()->updated_at);
        $this->assertEquals($current->updated_at, $current->fresh()->updated_at);

        // Composer packages only — the npm package was never asked about.
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'ui'));
    }

    public function test_nothing_is_sent_while_it_is_off(): void
    {
        config(['registry.plumb.enabled' => false]);

        Http::fake();

        Package::factory()->create(['name' => 'acme/widgets']);

        $this->artisan('plumb:refresh')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_rate_limit_stops_the_run_and_keeps_what_was_stored(): void
    {
        Http::fake(['plumbphp.dev/*' => Http::response([], 429)]);

        $package = Package::factory()->create(['name' => 'acme/widgets', 'plumb' => ['scores' => ['composite' => 88]]]);
        Package::factory()->create(['name' => 'acme/gadgets']);

        $this->artisan('plumb:refresh')->assertFailed();

        $this->assertSame(88, $package->fresh()->plumb['scores']['composite']);
        Http::assertSentCount(1);
    }

    public function test_the_panel_shows_the_score_beside_the_package_and_its_versions(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $summary = ['version' => 'v2.0.0', 'scanned_at' => '2026-10-03T20:30:21+00:00', 'scores' => ['composite' => 90.6, 'security' => 100, 'maintenance' => 80, 'ecosystem' => null]];

        $package = Package::factory()->create(['name' => 'acme/widgets', 'plumb' => $summary]);
        $version = PackageVersion::factory()->for($package)->create(['version' => '2.0.0', 'plumb' => $summary]);

        Livewire::test(ListPackages::class)
            ->assertTableColumnStateSet('plumb', 91, $package);

        Livewire::test(VersionsRelationManager::class, ['ownerRecord' => $package, 'pageClass' => ViewPackage::class])
            ->assertTableColumnStateSet('plumb', 91, $version);

        Livewire::test(ViewPackage::class, ['record' => $package->getKey()])
            ->assertSee('Security 100 · Maintenance 80 · Ecosystem unscored — scanned against v2.0.0 on Oct 3, 2026. Powered by Plumb.');
    }
}
