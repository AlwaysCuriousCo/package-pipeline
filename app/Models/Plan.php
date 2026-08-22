<?php

namespace App\Models;

use App\Enums\BillingModel;
use App\Enums\CancellationTiming;
use App\Enums\LapseBehaviour;
use App\Models\Concerns\LogsAuditableChanges;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What is for sale: a named bundle of entitlements and every lifecycle
 * decision that governs a subscription to it, held as data.
 *
 * The plan is the configuration object of the whole billing layer. What it
 * grants, how it charges, what lapsing does, how cancellation times out, how
 * many tokens it permits — all properties of the plan, so the same registry
 * can sell a strict per-seat subscription beside a perpetual licence beside a
 * comped sponsorship without any of them being a special case in code.
 *
 * Local rows are the source of truth; CatalogSync mirrors them out to
 * merchants and MerchantReference remembers the mapping.
 *
 * @see docs/plans/ecommerce-subscriptions.md
 */
#[Fillable([
    'name', 'slug', 'description', 'active', 'listed', 'billing_model',
    'trial_days', 'token_limit', 'updates_window_months', 'lapse_behaviour',
    'grace_days', 'cancellation', 'auto_issue_token', 'sort',
])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory, LogsAuditableChanges, SoftDeletes;

    /**
     * Everything commercial about a plan is worth an audit line: each of
     * these changes what customers get or pay for.
     *
     * @return list<string>
     */
    protected function auditedAttributes(): array
    {
        return [
            'name', 'slug', 'active', 'listed', 'billing_model', 'trial_days',
            'token_limit', 'updates_window_months', 'lapse_behaviour',
            'grace_days', 'cancellation', 'auto_issue_token',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'listed' => 'boolean',
            'billing_model' => BillingModel::class,
            'trial_days' => 'integer',
            'token_limit' => 'integer',
            'updates_window_months' => 'integer',
            'lapse_behaviour' => LapseBehaviour::class,
            'grace_days' => 'integer',
            'cancellation' => CancellationTiming::class,
            'auto_issue_token' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * @return HasMany<PlanPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    /**
     * @return HasMany<PlanEntitlement, $this>
     */
    public function entitlements(): HasMany
    {
        return $this->hasMany(PlanEntitlement::class);
    }

    /**
     * The entitlements as their targets, for the form pickers — the same
     * table as entitlements() above, read through the morph so Filament can
     * sync it like any other relationship.
     *
     * @return MorphToMany<Package, $this>
     */
    public function packages(): MorphToMany
    {
        return $this->morphedByMany(Package::class, 'grantable', 'plan_entitlements');
    }

    /**
     * @return MorphToMany<Repository, $this>
     */
    public function repositories(): MorphToMany
    {
        return $this->morphedByMany(Repository::class, 'grantable', 'plan_entitlements');
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The prices the buy button offers, defaults first.
     *
     * @return HasMany<PlanPrice, $this>
     */
    public function activePrices(): HasMany
    {
        return $this->prices()->where('active', true)->orderByDesc('default')->orderBy('amount');
    }

    /**
     * Plans the public pricing page lists, in their configured order.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeListed(Builder $query): Builder
    {
        return $query->where('active', true)->where('listed', true)->orderBy('sort');
    }

    /** Whether a stranger can buy this plan right now. */
    public function purchasable(): bool
    {
        return $this->active && $this->activePrices()->exists();
    }
}
