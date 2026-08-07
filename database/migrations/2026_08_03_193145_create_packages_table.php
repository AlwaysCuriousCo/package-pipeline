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
        Schema::create('packages', function (Blueprint $table) {
            $table->id();

            // Deleting a source leaves its packages in place; they fall back
            // to their own token or GITHUB_TOKEN until relinked.
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();

            $table->string('repository')->unique();
            $table->string('latest_version')->nullable();

            // Composer resolves packages by name, so it must be unique.
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->string('type')->nullable()->index();
            $table->text('token')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('sync_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};
