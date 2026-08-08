<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Filament\Widgets\DownloadsChart;
use App\Filament\Widgets\RegistryStatsOverview;
use App\Models\Download;
use App\Models\Package;
use App\Models\Token;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Download analytics: every served dist writes a row and bumps the
 * denormalized counters; the dashboard and search read them back.
 */
class DownloadStatsTest extends TestCase
{
    use RefreshDatabase;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        config(['filesystems.dists' => 's3']);
        Storage::fake('s3');
        Storage::disk('s3')->put('packages/acme/widgets/v100.zip', 'zip-bytes');

        $this->package = Package::factory()->create(['name' => 'acme/widgets']);

        $this->package->versions()->create([
            'version' => 'v1.0.0',
            'reference' => str_repeat('a', 40),
            'is_dev' => false,
            'archive_path' => 'packages/acme/widgets/v100.zip',
            'shasum' => sha1('zip-bytes'),
            'metadata' => ['name' => 'acme/widgets', 'version' => 'v1.0.0'],
        ]);
    }

    private function download(): void
    {
        $this->get('/dist/acme/widgets/'.str_repeat('a', 40).'.zip')->assertOk();
    }

    public function test_a_served_dist_records_a_download_and_bumps_the_counters(): void
    {
        $this->download();

        $version = $this->package->versions()->sole();
        $download = Download::query()->sole();

        $this->assertSame($this->package->id, $download->package_id);
        $this->assertSame($version->id, $download->package_version_id);
        $this->assertSame('v1.0.0', $download->version);
        $this->assertNull($download->token_prefix);

        $this->assertSame(1, $this->package->fresh()->total_downloads);
        $this->assertSame(1, $version->fresh()->total_downloads);
    }

    public function test_the_token_that_fetched_is_recorded_by_prefix(): void
    {
        $new = Token::issue(User::factory()->superAdmin()->create(), 'ci', [TokenAbility::RepositoryRead]);

        $this->withToken($new->plainText)
            ->get('/dist/acme/widgets/'.str_repeat('a', 40).'.zip')
            ->assertOk();

        $this->assertSame($new->token->token_prefix, Download::query()->sole()->token_prefix);
    }

    public function test_a_404_records_nothing(): void
    {
        $this->get('/dist/acme/widgets/'.str_repeat('f', 40).'.zip')->assertNotFound();

        $this->assertSame(0, Download::query()->count());
    }

    public function test_search_serves_the_real_download_count(): void
    {
        $this->download();
        $this->download();

        $this->get('/search.json?q=acme/')
            ->assertOk()
            ->assertJsonPath('results.0.downloads', 2);
    }

    public function test_recalculate_rebuilds_the_counters_from_the_rows(): void
    {
        $this->download();
        $this->download();

        // Knock both counters out of step, as a botched import would.
        $this->package->forceFill(['total_downloads' => 99])->save();
        $this->package->versions()->update(['total_downloads' => 99]);

        $this->artisan('downloads:recalculate')->assertSuccessful();

        $this->assertSame(2, $this->package->fresh()->total_downloads);
        $this->assertSame(2, $this->package->versions()->sole()->total_downloads);
    }

    public function test_the_dashboard_widgets_render_with_scoped_data(): void
    {
        $this->download();

        $this->actingAs(User::factory()->superAdmin()->create());

        Livewire::test(RegistryStatsOverview::class)
            ->assertOk()
            ->assertSee('Downloads · 30 days');

        Livewire::test(DownloadsChart::class)->assertOk();
    }

    public function test_the_download_history_survives_pruning_the_version(): void
    {
        $this->download();

        $this->package->versions()->sole()->delete();

        $download = Download::query()->sole();

        $this->assertNull($download->package_version_id);
        $this->assertSame('v1.0.0', $download->version);
    }
}
