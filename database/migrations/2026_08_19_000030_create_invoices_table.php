<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A local mirror of every invoice the merchant issues.
 *
 * The merchant renders and hosts the documents; these rows exist so the
 * customer area and the panel can show billing history without a live API
 * call, and so the history survives a merchant migration. Written by webhook
 * (invoice paid, refunded) and backfilled by the reconciler.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('billing_customer_id')->constrained()->cascadeOnDelete();

            // Nullable: a one-off charge can invoice a customer with no
            // subscription attached.
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();

            $table->string('merchant', 32);

            // The merchant's own invoice number — what the customer quotes
            // and what accounting reconciles against.
            $table->string('number')->nullable();

            // Integer minor units throughout, matching plan_prices. Tax is
            // whatever the merchant computed and collected.
            $table->string('currency', 3);
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('tax')->default(0);
            $table->unsignedBigInteger('total');
            $table->unsignedBigInteger('amount_refunded')->default(0);

            $table->string('status', 32);

            // Merchant-hosted pages; this app never renders an invoice.
            $table->string('hosted_url', 2048)->nullable();
            $table->string('pdf_url', 2048)->nullable();

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            $table->timestamps();

            $table->index(['billing_customer_id', 'issued_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
