<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which subscription a token was issued under, when one was.
 *
 * Two behaviours need the answer. A plan with a token_limit counts the live
 * tokens carrying its subscription's id, and LapseBehaviour::RevokeTokens
 * revokes exactly those — never a personal token the same user issued for
 * something else, which is the difference between "your subscription ended"
 * and "your account broke".
 *
 * nullOnDelete rather than cascade: tokens are soft-deleted audit history,
 * and a hard-deleted subscription must not silently hard-delete credentials'
 * records with it.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('access_tokens', function (Blueprint $table) {
            $table->foreignId('subscription_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subscription_id');
        });
    }
};
