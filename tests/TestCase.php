<?php

namespace Tests;

use App\Support\HostResolver;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
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
     * The resolver the app under test is using, to teach it a host.
     */
    protected function resolver(): FakeHostResolver
    {
        /** @var FakeHostResolver $resolver */
        $resolver = app(HostResolver::class);

        return $resolver;
    }
}
