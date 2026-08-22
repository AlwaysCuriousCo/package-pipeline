<?php

namespace App\Models;

use App\Enums\MerchantProvider;
use App\Enums\SubscriptionStatus;
use App\Merchants\MerchantClient;
use App\Models\Concerns\LogsAuditableChanges;
use App\Services\Billing\EntitlementProjector;
use App\Services\Billing\SubscriptionProjector;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer's purchase of a plan — a local mirror of the merchant's record.
 *
 * The merchant owns the billing clock. This row is a projection of what it
 * last said, written by verified webhooks and repaired by the nightly
 * reconciler; nothing local ever decides that a renewal happened. What *is*
 * decided locally is what the money means: the entitlement projector turns
 * this row's status into grants, and the plan's lapse behaviour into their
 * withdrawal.
 *
 * @see SubscriptionProjector
 * @see EntitlementProjector
 */
#[Fillable(['billing_customer_id', 'plan_id', 'plan_price_id', 'merchant', 'status', 'quantity', 'coupon_code'])]
class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory, LogsAuditableChanges, SoftDeletes;

    /**
     * The commercial identity and the two administrative acts. The clock
     * columns move on every merchant event and would bury the audit log in
     * bookkeeping.
     *
     * @return list<string>
     */
    protected function auditedAttributes(): array
    {
        return ['plan_id', 'plan_price_id', 'merchant', 'status', 'suspended_at', 'suspension_reason'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'merchant' => MerchantProvider::class,
            'status' => SubscriptionStatus::class,
            'quantity' => 'integer',
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'grace_ends_at' => 'datetime',
            'grace_notified_at' => 'datetime',
            'cancel_at' => 'datetime',
            'canceled_at' => 'datetime',
            'ends_at' => 'datetime',
            'suspended_at' => 'datetime',
            'last_event_at' => 'datetime',
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
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<PlanPrice, $this>
     */
    public function price(): BelongsTo
    {
        return $this->belongsTo(PlanPrice::class, 'plan_price_id');
    }

    /**
     * @return HasMany<Entitlement, $this>
     */
    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }

    /**
     * The access tokens this subscription issued — what a token_limit counts
     * and what LapseBehaviour::RevokeTokens revokes.
     *
     * @return HasMany<Token, $this>
     */
    public function tokens(): HasMany
    {
        return $this->hasMany(Token::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function client(): MerchantClient
    {
        return $this->merchant->client();
    }

    /**
     * Whether this subscription grants access right now. Suspension wins
     * over everything — it is the administrative hard stop — and the grace
     * window extends a lapsed status without rewriting it, so the panel can
     * still show *why* the clock is running.
     */
    public function grantsAccess(): bool
    {
        if ($this->suspended_at !== null) {
            return false;
        }

        if ($this->status->grantsAccess()) {
            return true;
        }

        return $this->grace_ends_at !== null && $this->grace_ends_at->isFuture();
    }

    /**
     * The subscriptions the nightly reconciler re-pulls from the merchant.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeReconcilable(Builder $query): Builder
    {
        return $query
            ->whereIn('status', SubscriptionStatus::reconcilable())
            ->where('merchant', '!=', MerchantProvider::Manual->value)
            ->orderBy('current_period_end');
    }
}
