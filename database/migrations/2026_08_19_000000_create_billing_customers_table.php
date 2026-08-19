<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The party that pays: who the card belongs to, who the invoice names, and
 * which merchant knows them.
 *
 * Polymorphic over User and Team because both really do buy access — a solo
 * developer subscribes for themselves, a company pays once for a team whose
 * members come and go. The entitlement projector resolves the difference; the
 * billing tables never care which kind of billable they carry.
 *
 * Deliberately not columns on users/teams: a customer is a different thing
 * from an account. Most users will never have one, a team's members must not
 * inherit the card, and the business fields (company name, tax id, address)
 * are invoice material with no meaning anywhere else in the app.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('billing_customers', function (Blueprint $table) {
            $table->id();

            // The account behind the money: a User or a Team.
            $table->morphs('billable');

            // Invoice identity, captured at checkout. Kept apart from the
            // user's own name/email because the person who pays is routinely
            // not the person who logs in — accounts-payable addresses, a
            // company name where the panel shows a person's.
            $table->string('name');
            $table->string('email');
            $table->string('company_name')->nullable();
            $table->string('tax_id')->nullable();
            $table->json('address')->nullable();

            // Which driver owns the remote half, and the remote customer's
            // id there. Nullable because a Manual customer has no remote
            // half; unique as a pair because one Stripe customer must map to
            // exactly one row here or webhooks fan out to the wrong account.
            $table->string('merchant', 32);
            $table->string('merchant_customer_id')->nullable();

            // A team's subscription still needs one human to email: dunning
            // notices, trial reminders, the auto-issued token. Teams have no
            // owner by design, so the nomination lives here, on the billing
            // relationship that needs it. Null on a User customer, who is
            // their own contact. nullOnDelete: losing the contact must not
            // take the paid subscription down with it.
            $table->foreignId('billing_contact_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Customers are never hard-deleted: invoices reference them and
            // accounting history has to survive an account cleanup.
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['merchant', 'merchant_customer_id']);
            $table->unique(['billable_type', 'billable_id', 'merchant']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('billing_customers');
    }
};
