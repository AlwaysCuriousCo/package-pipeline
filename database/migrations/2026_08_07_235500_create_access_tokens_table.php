<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('access_tokens', function (Blueprint $table) {
            $table->id();

            // The principal the token acts as: a user's personal token, or a
            // deploy token standing in for a CI system.
            $table->morphs('tokenable');

            $table->string('name');

            // Only the sha256 of the plain token is stored — the secret is
            // shown once at creation and never again. The prefix is what
            // listings show, so a token can be recognised without it.
            $table->string('token_prefix', 16);
            $table->string('token', 64)->unique();

            $table->json('abilities');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Revoking is a soft delete, so "what was that token and when was
            // it last used" stays answerable after the fact.
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('access_tokens');
    }
};
