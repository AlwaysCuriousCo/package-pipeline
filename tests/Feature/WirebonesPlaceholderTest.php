<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filament renders dashboard widgets lazily, so the page ships a placeholder
 * first and swaps in the widget once it resolves. Wirebones replaces that
 * placeholder with a skeleton captured from the real rendered widget, which is
 * what keeps the swap from shifting the layout.
 *
 * The skeletons in resources/wirebones are committed build artifacts, because
 * capturing them needs Playwright's Chromium and deploy boxes do not have it.
 * That makes them exactly as stale as the last `wirebones:build` — this test
 * is the tripwire for the wiring going missing, not for the shapes drifting.
 */
class WirebonesPlaceholderTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_widgets_render_generated_skeletons(): void
    {
        $this->assertFileExists(
            resource_path('wirebones/views/widgets.registry-totals.blade.php'),
            'Committed Wirebones skeletons are missing — run `php artisan wirebones:build`.',
        );

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get('/admin')
            ->assertOk()
            ->assertSee('wirebones-bone', escape: false);
    }
}
