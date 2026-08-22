<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscriptions, and the ledger of what each one granted.
 *
 * The subscription row is a projection: the merchant owns the billing clock
 * and this table mirrors it, updated by verified webhooks and repaired by the
 * nightly reconciler. Nothing here decides when a renewal happens; it records
 * what the merchant said and when it said it, which is why `last_event_at`
 * exists — an out-of-order webhook must not roll a newer truth back.
 *
 * The entitlements table is the projector's own ledger. The grant pivots
 * answer "who can see what" on the hot path; this answers "which subscription
 * granted it, and up to which version" — the question the metadata and dist
 * paths ask only for a package reached through a subscription, and the record
 * that survives to say what a lapsed perpetual licence still owns.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('billing_customer_id')->constrained()->cascadeOnDelete();

            // What was bought and at which price. Restrict rather than
            // cascade: deleting a plan out from under live subscriptions is
            // an operator error the database should refuse.
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_price_id')->constrained()->restrictOnDelete();

            $table->string('merchant', 32);

            // SubscriptionStatus — the canonical vocabulary every driver
            // normalises into.
            $table->string('status', 32);

            $table->unsignedInteger('quantity')->default(1);

            // The merchant's clock, mirrored. All nullable: a Manual
            // subscription fills only what its administrator typed, and a
            // one-time purchase has an updates window rather than periods.
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();

            // When the plan's own grace window closes, stamped by the
            // projector when the merchant first reports the lapse. Null when
            // the plan follows the merchant's dunning alone.
            $table->timestamp('grace_ends_at')->nullable();

            // When the reconciler's grace-end sweep sent the lapse notice,
            // so a later run does not mail the customer again. Cleared with
            // the grace clock when granting resumes.
            $table->timestamp('grace_notified_at')->nullable();

            // Cancellation is two moments: when it was requested, and when it
            // takes effect. `ends_at` is the general "stopped granting" stamp
            // whatever the cause.
            $table->timestamp('cancel_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            // The administrative hard stop. Set and cleared only in the
            // panel; no merchant event touches it.
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();

            // The code the customer entered, kept for reporting. The merchant
            // applied the actual discount.
            $table->string('coupon_code')->nullable();

            // The newest merchant truth already applied, so a delayed webhook
            // arriving after a fresher one (or after a reconcile) is ignored
            // rather than applied backwards.
            $table->timestamp('last_event_at')->nullable();

            $table->softDeletes();
            $table->timestamps();

            // The reconciler's sweep: every subscription in a status the
            // merchant might still move, oldest period first.
            $table->index(['status', 'current_period_end']);
        });

        Schema::create('entitlements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();

            // Denormalised from the subscription so "everything this customer
            // is entitled to" is one indexed read at request time.
            $table->foreignId('billing_customer_id')->constrained()->cascadeOnDelete();

            // What was granted: a Repository or a Package, same vocabulary as
            // plan_entitlements — this is that row, instantiated for one
            // subscription.
            $table->morphs('grantable');

            // The highest version this entitlement reaches, normalised, or
            // null for no ceiling. Pinned by the projector when a
            // freeze-at-version plan lapses or an updates window closes;
            // cleared on renewal.
            $table->string('version_ceiling')->nullable();

            $table->boolean('active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->timestamps();

            $table->unique(['subscription_id', 'grantable_type', 'grantable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entitlements');
        Schema::dropIfExists('subscriptions');
    }
};
