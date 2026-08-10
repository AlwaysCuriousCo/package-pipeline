<?php

namespace Tests\Feature;

use App\Models\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The Composer endpoints answer a client that holds no cookie and sends none,
 * so a session started for one is a row nothing will ever read again — and on
 * the shipped `database` driver, a row nothing ever prunes either.
 *
 * `composer update` fetches metadata per package in the graph, so this is the
 * difference between a registry that stores nothing per install and one that
 * stores several hundred rows per install, forever.
 */
class ComposerStatelessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The shipped default, and the one where the cost is a database write
        // rather than a discarded array.
        config(['session.driver' => 'database']);

        Package::factory()
            ->create(['name' => 'acme/widgets'])
            ->versions()->create([
                'version' => '1.0.0',
                'reference' => str_repeat('a', 40),
                'is_dev' => false,
                'metadata' => ['name' => 'acme/widgets', 'version' => '1.0.0'],
            ]);
    }

    /**
     * @return list<string>
     */
    private function composerPaths(): array
    {
        return [
            '/packages.json',
            '/list.json',
            '/search.json?q=acme',
            '/p2/acme/widgets.json',
            '/security-advisories?packages[]=acme/widgets',
        ];
    }

    public function test_reading_the_registry_stores_no_sessions(): void
    {
        foreach ($this->composerPaths() as $path) {
            $this->get($path)->assertOk();
        }

        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_posting_a_package_list_stores_no_session(): void
    {
        // The one read Composer makes by POST, and the one that would
        // otherwise need a CSRF token it has nowhere to get.
        $this->post('/security-advisories', ['packages' => ['acme/widgets']])
            ->assertOk()
            ->assertJsonPath('advisories.acme/widgets', []);

        $this->assertSame(0, DB::table('sessions')->count());
    }

    /**
     * Stated against the route table as well as the behaviour, because the
     * cost only reappears if someone moves these routes back into web.php —
     * where the session middleware is inherited rather than asked for.
     */
    public function test_no_composer_route_carries_the_session_middleware(): void
    {
        $composer = array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RegisteredRoute $route): bool => str_starts_with((string) $route->getName(), 'composer.'),
        );

        $this->assertNotEmpty($composer);

        foreach ($composer as $route) {
            $this->assertNotContains(
                StartSession::class,
                Route::gatherRouteMiddleware($route),
                "The route [{$route->uri()}] starts a session.",
            );
        }
    }
}
