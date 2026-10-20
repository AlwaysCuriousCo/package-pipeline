<?php

namespace App\Jobs;

use App\Models\Plan;
use App\Services\Billing\EntitlementProjector;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-projects every subscription on a plan after the plan changed.
 *
 * Adding a package to a plan is adding it for everyone already subscribed,
 * and removing one is the reverse — the projector computes both from current
 * state, so this job is nothing but "run it for each affected customer".
 *
 * Unique per plan until the sweep starts: saves made before it begins
 * collapse into one sweep, and a save made while it is running queues exactly
 * one more. Held for the whole run instead, that second save would be
 * dropped, and every customer the sweep had already passed would keep the
 * entitlements of the plan as it was before the save — with nothing queued
 * to put that right.
 */
class ReprojectPlanEntitlements implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    // Held to the queue's ceiling (see config/queue.php): past retry_after a
    // second worker would start the same sweep while this one is still in it.
    // The projector works from current state, so a retry after a timeout
    // simply runs the sweep again.
    public int $timeout = 300;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public readonly int $planId) {}

    public function uniqueId(): string
    {
        return (string) $this->planId;
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(EntitlementProjector $projector): void
    {
        $plan = Plan::query()->find($this->planId);

        if ($plan === null) {
            return;
        }

        $plan->subscriptions()
            ->with('customer')
            ->chunkById(100, function ($subscriptions) use ($projector): void {
                foreach ($subscriptions->pluck('customer')->filter()->unique('id') as $customer) {
                    $projector->projectCustomer($customer);
                }
            });
    }
}
