<?php

namespace App\Models;

use App\Enums\MerchantProvider;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A local mirror of an invoice the merchant issued.
 *
 * The merchant renders and hosts the documents; these rows are what lets the
 * customer area and the panel show billing history without a live API call,
 * and what survives a merchant migration. Written by webhooks, backfilled by
 * the reconciler, never authored here.
 */
#[Fillable([
    'subscription_id', 'merchant', 'number', 'currency', 'subtotal', 'tax',
    'total', 'amount_refunded', 'status', 'hosted_url', 'pdf_url',
    'issued_at', 'paid_at', 'refunded_at',
])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merchant' => MerchantProvider::class,
            'subtotal' => 'integer',
            'tax' => 'integer',
            'total' => 'integer',
            'amount_refunded' => 'integer',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BillingCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(BillingCustomer::class, 'billing_customer_id');
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /** Whether the merchant returned the whole charge. */
    public function fullyRefunded(): bool
    {
        return $this->total > 0 && $this->amount_refunded >= $this->total;
    }
}
