<?php

namespace App\Console\Commands;

use App\Enums\MerchantProvider;
use App\Services\Billing\CatalogSync;
use Illuminate\Console\Command;

/**
 * Pushes the plan catalog to the configured merchant — the pipeline's way to
 * run what the panel's resync action runs. Idempotent; run it whenever.
 */
class SyncBillingCatalog extends Command
{
    protected $signature = 'billing:sync-catalog {--merchant= : Which merchant to sync to (defaults to the configured one)}';

    protected $description = 'Create or update the merchant\'s products and prices from the local plan catalog';

    public function handle(CatalogSync $sync): int
    {
        $merchant = MerchantProvider::tryFrom((string) ($this->option('merchant') ?? config('registry.billing.merchant')));

        if ($merchant === null) {
            $this->error('Unknown merchant.');

            return self::FAILURE;
        }

        $count = $sync->syncAll($merchant);

        $this->info("Synced {$count} plan(s) to {$merchant->getLabel()}.");

        return self::SUCCESS;
    }
}
