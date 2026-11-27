<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Tests\TestCase;

class LockedPropertyReportingTest extends TestCase
{
    public function test_locked_property_tampering_is_not_reported(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        $this->assertFalse($handler->shouldReport(
            new CannotUpdateLockedPropertyException('areSchemaStateUpdateHooksDisabledForTesting'),
        ));
    }
}
