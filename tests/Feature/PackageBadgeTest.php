<?php

namespace Tests\Feature;

use App\Models\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PackageBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function package(bool $page = true): Package
    {
        $package = Package::factory()->create([
            'name' => 'acme/widgets',
            'latest_version' => 'v1.1.0',
            'total_downloads' => 1500,
            'page_enabled' => $page,
        ]);

        $package->versions()->create([
            'version' => 'v1.1.0',
            'order' => '1.1.0.0',
            'reference' => str_repeat('b', 40),
            'is_dev' => false,
            'released_at' => '2026-02-01 12:00:00',
            'shasum' => sha1('zip'),
            'metadata' => ['name' => 'acme/widgets', 'license' => 'MIT', 'require' => ['php' => '^8.3']],
        ]);

        return $package;
    }

    public function test_badges_render_the_package_facts(): void
    {
        $this->package();

        foreach (['version' => 'v1.1.0', 'downloads' => '1.5k', 'license' => 'MIT', 'php' => '^8.3'] as $kind => $value) {
            $this->get("/p/acme/widgets/badge/{$kind}.svg")
                ->assertOk()
                ->assertHeader('Content-Type', 'image/svg+xml')
                ->assertSee(e($value), false);
        }
    }

    public function test_badges_need_a_page_and_a_known_kind(): void
    {
        $package = $this->package(page: false);

        $this->get('/p/acme/widgets/badge/version.svg')->assertNotFound();

        $package->forceFill(['page_enabled' => true])->save();

        $this->get('/p/acme/widgets/badge/stars.svg')->assertNotFound();
    }

    public function test_badge_markdown_links_every_badge_to_the_page(): void
    {
        $markdown = $this->package()->badgeMarkdown();

        $this->assertStringContainsString('/p/acme/widgets/badge/version.svg', $markdown);
        $this->assertStringContainsString('/p/acme/widgets/badge/php.svg)](', $markdown);
    }
}
