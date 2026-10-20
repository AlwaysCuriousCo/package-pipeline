<?php

namespace Tests\Feature;

use App\Jobs\ReprojectPlanEntitlements;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The plan re-projection is unique per plan, but only until it starts: a save
 * made while the sweep is running has to queue a follow-up rather than be
 * dropped, or every customer the sweep had already passed keeps the plan as
 * it was before that save.
 */
class ReprojectPlanEntitlementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite runs on the sync driver, which has no queue to coalesce on.
        config(['queue.default' => 'database']);
    }

    public function test_saves_before_the_sweep_starts_collapse_into_one(): void
    {
        $plan = Plan::factory()->create();

        ReprojectPlanEntitlements::dispatch($plan->id);
        ReprojectPlanEntitlements::dispatch($plan->id);

        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_a_save_during_the_sweep_queues_exactly_one_follow_up(): void
    {
        $plan = Plan::factory()->create();

        ReprojectPlanEntitlements::dispatch($plan->id);
        $this->assertDatabaseCount('jobs', 1);

        // The job reads its plan first thing, so a save that lands then is a
        // save during the sweep — after the worker has picked the job up.
        // Two of them, to show they still collapse into one follow-up.
        $saved = false;
        Plan::retrieved(function () use ($plan, &$saved): void {
            if ($saved) {
                return;
            }

            $saved = true;

            ReprojectPlanEntitlements::dispatch($plan->id);
            ReprojectPlanEntitlements::dispatch($plan->id);
        });

        $this->workQueue(['--once' => true])->assertSuccessful();

        $this->assertTrue($saved);
        $this->assertDatabaseCount('failed_jobs', 0);

        // Not dropped, and not two: one follow-up, for the same plan.
        $this->assertDatabaseCount('jobs', 1);

        $payload = json_decode((string) DB::table('jobs')->value('payload'), true);

        $this->assertSame(ReprojectPlanEntitlements::class, $payload['displayName']);
        $this->assertStringContainsString('"planId";i:'.$plan->id.';', $payload['data']['command']);
    }
}
