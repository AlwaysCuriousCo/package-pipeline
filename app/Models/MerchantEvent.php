<?php

namespace App\Models;

use App\Enums\MerchantProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * The inbox: one verified webhook delivery from a merchant.
 *
 * Written before anything acts, processed on the queue, kept afterwards as
 * the forensic record of what the merchant said and when. The unique
 * (merchant, external_id) pair is the idempotency guarantee — a redelivery
 * inserts nothing and is acknowledged without running twice.
 */
#[Fillable(['merchant', 'external_id', 'type', 'payload', 'received_at'])]
class MerchantEvent extends Model
{
    use Prunable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merchant' => MerchantProvider::class,
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function markProcessed(): void
    {
        $this->forceFill(['processed_at' => now(), 'failed_at' => null, 'error' => null])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill(['failed_at' => now(), 'error' => $error])->save();
    }

    /**
     * Processed events older than a year age out with the nightly
     * `model:prune`. Failed and unprocessed rows are kept indefinitely —
     * they are the queue of things still owed an action, and pruning one
     * would be silently dropping a payment event.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->whereNotNull('processed_at')
            ->where('received_at', '<', now()->subYear());
    }
}
