<?php

namespace App\Models;

use Database\Factories\PlanEntitlementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One thing a plan grants: a Repository wholesale, or a Package by name —
 * the same two shapes a grant has always had.
 *
 * The template only. When a subscription activates, the projector copies
 * these into Entitlement rows and pivot grants for the actual beneficiaries;
 * editing a plan's entitlements re-projects every active subscription, so
 * adding a package to a plan is adding it for everyone on the plan.
 */
#[Fillable(['grantable_type', 'grantable_id'])]
class PlanEntitlement extends Model
{
    /** @use HasFactory<PlanEntitlementFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function grantable(): MorphTo
    {
        return $this->morphTo();
    }
}
