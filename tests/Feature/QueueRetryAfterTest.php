<?php

namespace Tests\Feature;

use App\Jobs\DiscoverVersions;
use App\Jobs\ImportVersion;
use App\Jobs\SyncPackageJob;
use ReflectionClass;
use Tests\TestCase;

/**
 * Once retry_after passes, the queue treats a job as abandoned and gives it to
 * a second worker — whether or not the first is still running it. A connection
 * whose retry_after sits at or below a job's own timeout therefore runs slow
 * imports twice over: two downloads, two archives written, both spending the
 * batch's attempts. Nothing at runtime complains, so the invariant is asserted
 * here instead of being rediscovered on a large repository.
 */
class QueueRetryAfterTest extends TestCase
{
    public function test_retry_after_outlives_the_longest_job_timeout(): void
    {
        $longest = max(array_map(
            fn (string $job): int => (new ReflectionClass($job))->getDefaultProperties()['timeout'],
            [DiscoverVersions::class, ImportVersion::class, SyncPackageJob::class],
        ));

        $configured = array_filter(
            config('queue.connections'),
            fn (array $connection): bool => isset($connection['retry_after']),
        );

        // A connection that quietly lost its retry_after would pass the loop
        // below by never entering it.
        $this->assertArrayHasKey('database', $configured);

        foreach ($configured as $name => $connection) {
            $this->assertGreaterThan(
                $longest,
                $connection['retry_after'],
                "The {$name} queue's retry_after must exceed the longest job timeout ({$longest}s).",
            );
        }
    }
}
