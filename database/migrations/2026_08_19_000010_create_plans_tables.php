<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catalog: what is for sale, at what prices, granting what.
 *
 * A plan is the configuration object the whole billing layer hangs off. It
 * names its entitlements, carries every lifecycle decision as data — what
 * lapsing does, how cancellation times out, how many tokens it permits — and
 * is the local source of truth that CatalogSync mirrors out to merchants.
 * Swapping merchants re-syncs this catalog; it never re-authors it.
 *
 * Prices are rows rather than columns so one plan can sell monthly and
 * yearly, in more than one currency, and retire a price without breaking the
 * subscriptions already on it. Amounts are integer minor units, never floats:
 * money in floats is how $19.99 becomes $19.989999.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            // The public page's URL segment, and the stable handle CatalogSync
            // names the plan by at the merchant.
            $table->string('slug')->unique();

            $table->text('description')->nullable();

            // `active` gates selling; `listed` gates the public pricing page.
            // Separate because an unlisted plan is still sellable by direct
            // link — launch offers, grandfathered tiers, one customer's
            // negotiated price.
            $table->boolean('active')->default(true);
            $table->boolean('listed')->default(false);

            // BillingModel: recurring, or one-time with an updates window.
            $table->string('billing_model', 32);

            // Days of trial before the first charge. 0 means none.
            $table->unsignedInteger('trial_days')->default(0);

            // How many access tokens a subscription may hold, null meaning
            // no cap. The auto-issued activation token counts toward it.
            $table->unsignedInteger('token_limit')->nullable();

            // Months of updates a one-time purchase includes. Null on
            // recurring plans, whose window is the billing period itself.
            $table->unsignedInteger('updates_window_months')->nullable();

            // LapseBehaviour: what stopping payment does to granted access.
            $table->string('lapse_behaviour', 32);

            // Days of continued access after the merchant reports the
            // subscription lapsed, before the lapse behaviour runs. Null
            // means follow the merchant alone: its dunning window (Stripe
            // retries for ~3 weeks) is already a grace period.
            $table->unsignedInteger('grace_days')->nullable();

            // CancellationTiming: whether the customer's own cancellation
            // cuts access immediately or lets the paid period run out.
            $table->string('cancellation', 32);

            // Whether activation mints a starter token and shows it once, so
            // the buyer leaves checkout with a working credential.
            $table->boolean('auto_issue_token')->default(true);

            // Position on the pricing page.
            $table->unsignedInteger('sort')->default(0);

            // Soft deletes: a retired plan is still what existing
            // subscriptions point at and what old invoices describe.
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();

            // ISO 4217, lowercase, e.g. "usd" — the form merchants speak.
            $table->string('currency', 3);

            // Integer minor units: 1999 is $19.99. Big enough for enterprise
            // prices in minor units of weak currencies.
            $table->unsignedBigInteger('amount');

            // BillingInterval (month / year / one_time) and its multiplier —
            // every 1 month, every 3 months.
            $table->string('interval', 16);
            $table->unsignedInteger('interval_count')->default(1);

            // Retired prices stay for the subscriptions on them; `default`
            // is the one the buy button preselects.
            $table->boolean('active')->default(true);
            $table->boolean('default')->default(false);

            $table->timestamps();
        });

        // What a plan grants, in exactly the vocabulary grants already use: a
        // repository wholesale, or a package by name. Polymorphic where the
        // user pivots are two tables, because this one is read only by the
        // projector — never on the Composer hot path — and a plan's grant
        // list is one list in the panel.
        Schema::create('plan_entitlements', function (Blueprint $table) {
            $table->id();

            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->morphs('grantable');

            $table->unique(['plan_id', 'grantable_type', 'grantable_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_entitlements');
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('plans');
    }
};
