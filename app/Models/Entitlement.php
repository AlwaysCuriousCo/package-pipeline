<?php

namespace App\Models;

use Database\Factories\EntitlementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The projector's ledger: one thing one subscription grants, and up to
 * which version.
 *
 * The grant pivots answer "who can see what" on the Composer hot path and
 * never learn what billing is. This table answers the questions only billing
 * asks — which subscription granted a package, and whether a version ceiling
 * caps it. The metadata and dist paths read it only for a package reached
 * through a subscription; everything else in the app never touches it.
 *
 * Rows outlive their usefulness on purpose: a lapsed freeze-at-version
 * entitlement with its pinned ceiling *is* the perpetual licence.
 */
#[Fillable(['billing_customer_id', 'grantable_type', 'grantable_id', 'version_ceiling', 'active', 'starts_at', 'ends_at'])]
class Entitlement extends Model
{
    /** @use HasFactory<EntitlementFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<BillingCustomer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(BillingCustomer::class, 'billing_customer_id');
    }

    /**
     * What was granted: a Repository or a Package.
     *
     * @return MorphTo<Model, $this>
     */
    public function grantable(): MorphTo
    {
        return $this->morphTo();
    }
}
