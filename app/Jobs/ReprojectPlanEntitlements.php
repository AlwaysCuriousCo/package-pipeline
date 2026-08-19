<?php

namespace App\Jobs;

use App\Models\Plan;
use App\Services\Billing\EntitlementProjector;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-projects every subscription on a plan after the plan changed.
 *
 * Adding a package to a plan is adding it for everyone already subscribed,
 * and removing one is the reverse — the projector computes both from current
 * state, so this job is nothing but "run it for each affected customer".
 * Unique per plan: two quick saves collapse into one sweep.
 */
class ReprojectPlanEntitlements implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

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
