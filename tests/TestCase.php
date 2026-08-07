<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
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
