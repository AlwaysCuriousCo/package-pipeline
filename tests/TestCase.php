<?php

namespace Tests;

use App\Support\HostResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;
use Tests\Support\FakeHostResolver;

abstract class TestCase extends BaseTestCase
{
    /**
     * Seed the permission rows once, when the test database is built.
     *
     * Shield's policies check permissions that only exist once they have been
     * generated, so without this every panel test would be asserting against
     * a blanket denial rather than against the rules being tested.
     */
    protected bool $seed = true;

    /**
     * A request the fakes do not cover reaches the real GitHub, where it fails
     * on credentials and reads as a bug in the code under test. Failing on the
     * stray request instead names the missing stub.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        // The mirror resolves every host it is about to fetch from before it
        // fetches, so a suite whose upstreams are `*.test` needs an answer for
        // them. Reached through the container, so a test with an opinion about
        // what a host resolves to states it on this instance.
        $this->app->instance(HostResolver::class, new FakeHostResolver);
    }

    /**
     * Run the real queue worker in-process, with its memory ceiling lifted.
     *
     * `queue:work` compares its `--memory` budget against
     * `memory_get_usage(true)`, which inside a test is the whole PHPUnit
     * process: the framework, every fixture the suite has built, and whatever
     * the tests before this one left behind. At the default 128 MB the worker
     * therefore measures the test runner rather than itself, decides it is
     * over budget, and exits 12 without running a single job — so the
     * assertion that fails is `assertSuccessful()`, naming nothing that is
     * actually wrong.
     *
     * Which test that lands on depends only on where in the run it sits, so it
     * arrives with an unrelated test being added and is not reproducible on
     * the failing file alone. Lifting the ceiling here rather than at each
     * call site is what keeps the next such call site from finding out again.
     *
     * @param  array<string, mixed>  $options
     */
    protected function workQueue(array $options = []): PendingCommand
    {
        return $this->artisan('queue:work', ['--memory' => 0, ...$options]);
    }

    /**
     * The resolver the app under test is using, to teach it a host.
     */
    protected function resolver(): FakeHostResolver
    {
        /** @var FakeHostResolver $resolver */
        $resolver = app(HostResolver::class);

        return $resolver;
    }
}
