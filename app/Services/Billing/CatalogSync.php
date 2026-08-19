<?php

namespace App\Services\Billing;

use App\Enums\MerchantProvider;
use App\Models\MerchantReference;
use App\Models\Plan;

/**
 * Pushes the local catalog out to a merchant and remembers the mapping.
 *
 * The local rows are the source of truth: plans and prices are authored in
 * the panel, and this makes the merchant agree — creating what it lacks,
 * updating what drifted, archiving prices that changed. Run before a plan's
 * first checkout, from the panel action, or wholesale from
 * `billing:sync-catalog`; running it twice is running it once, because the
 * drivers converge rather than append.
 */
final class CatalogSync
{
    /** Push one plan and all its prices. */
    public function syncPlan(Plan $plan, MerchantProvider $merchant): void
    {
        $client = $merchant->client();

        MerchantReference::remember($plan, $merchant, $client->syncProduct($plan));

        foreach ($plan->prices as $price) {
            MerchantReference::remember($price, $merchant, $client->syncPrice($price));
        }
    }

    /** Push every active plan. */
    public function syncAll(MerchantProvider $merchant): int
    {
        $count = 0;

        foreach (Plan::query()->where('active', true)->with('prices')->get() as $plan) {
            $this->syncPlan($plan, $merchant);
            $count++;
        }

        return $count;
    }
}
