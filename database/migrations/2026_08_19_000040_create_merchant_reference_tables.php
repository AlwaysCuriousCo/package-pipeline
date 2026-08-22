<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two tables that keep the merchant integration honest.
 *
 * merchant_references maps local rows to their remote counterparts. The local
 * catalog is the source of truth; when CatalogSync pushes a plan to Stripe it
 * records the Product id here, and when a webhook names a Stripe object this
 * is how it finds the local row. One local row can be known to several
 * merchants at once, which is the whole portability story: a second merchant
 * is a second row in this table, not a second schema.
 *
 * merchant_events is the inbox. Every verified webhook is written here before
 * anything acts on it, and the unique external id is what makes replays
 * harmless — a merchant retrying a delivery, or an operator replaying a
 * failed one, inserts nothing twice. Processing happens on the queue against
 * this row, so a deploy mid-webhook loses nothing.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('merchant_references', function (Blueprint $table) {
            $table->id();

            // The local row: a Plan, PlanPrice, BillingCustomer,
            // Subscription or Invoice.
            $table->morphs('referenceable');

            $table->string('merchant', 32);
            $table->string('external_id');
            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            // One remote object per local row per merchant, and one local
            // row per remote object — lookups run in both directions.
            $table->unique(['referenceable_type', 'referenceable_id', 'merchant'], 'merchant_references_local_unique');
            $table->unique(['merchant', 'external_id']);
        });

        Schema::create('merchant_events', function (Blueprint $table) {
            $table->id();

            $table->string('merchant', 32);

            // The merchant's own event id — the idempotency key. Unique is
            // the dedupe: a redelivered event fails the insert and is
            // acknowledged without being processed twice.
            $table->string('external_id');

            // The merchant's event name, verbatim (e.g. Stripe's
            // "customer.subscription.updated"), for filtering and forensics.
            $table->string('type');

            $table->json('payload');

            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error')->nullable();

            // No updated_at bookkeeping worth keeping; created_at comes from
            // timestamps() and received_at is the meaningful moment.
            $table->timestamps();

            $table->unique(['merchant', 'external_id']);
            $table->index(['merchant', 'processed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_events');
        Schema::dropIfExists('merchant_references');
    }
};
