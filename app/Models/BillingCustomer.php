<?php

namespace App\Models;

use App\Enums\MerchantProvider;
use App\Merchants\MerchantClient;
use App\Models\Concerns\LogsAuditableChanges;
use Database\Factories\BillingCustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The party that pays: a User or a Team with a card on file at a merchant.
 *
 * Deliberately a separate thing from the account. Most users never have one,
 * a team's members must not inherit the card, and everything here — company
 * name, tax id, the address an invoice names — is billing material with no
 * meaning elsewhere in the app. The account answers "who is this"; this row
 * answers "who gets charged".
 *
 * @see docs/plans/ecommerce-subscriptions.md
 */
#[Fillable(['name', 'email', 'company_name', 'tax_id', 'address', 'merchant', 'billing_contact_user_id'])]
class BillingCustomer extends Model
{
    /** @use HasFactory<BillingCustomerFactory> */
    use HasFactory, LogsAuditableChanges, SoftDeletes;

    /**
     * Who the invoices name — not the merchant ids, which are plumbing, and
     * not the address, whose churn is noise beside the identity fields.
     *
     * @return list<string>
     */
    protected function auditedAttributes(): array
    {
        return ['name', 'email', 'company_name', 'tax_id', 'merchant', 'billable_type', 'billable_id'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'address' => 'array',
            'merchant' => MerchantProvider::class,
        ];
    }

    /**
     * The account behind the money: a User, or a Team.
     *
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<Entitlement, $this>
     */
    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function billingContact(): BelongsTo
    {
        return $this->belongsTo(User::class, 'billing_contact_user_id');
    }

    /** The merchant driver this customer is billed through. */
    public function client(): MerchantClient
    {
        return $this->merchant->client();
    }

    /**
     * The one human to email about this customer's billing: the account
     * itself when it is a person, the nominated contact when it is a team.
     * Null only for a team that never nominated anyone, which the checkout
     * flow does not allow but an admin-created customer can be.
     */
    public function contact(): ?User
    {
        $billable = $this->billable;

        if ($billable instanceof User) {
            return $billable;
        }

        return $this->billingContact;
    }

    /**
     * Every user this customer's subscriptions grant access to — the person
     * themselves, or the team's whole membership. The entitlement projector
     * asks this to know whose pivot rows to write; nothing else should, so
     * that the answer has exactly one definition.
     *
     * @return list<User>
     */
    public function beneficiaries(): array
    {
        $billable = $this->billable;

        if ($billable instanceof User) {
            return [$billable];
        }

        if ($billable instanceof Team) {
            return $billable->users()->get()->all();
        }

        return [];
    }
}
