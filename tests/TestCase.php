<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

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
    }
}
