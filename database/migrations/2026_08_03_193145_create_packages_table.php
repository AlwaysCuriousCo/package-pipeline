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

            // The job batch currently (or last) rebuilding this package's
            // versions, so the panel can show the imports' progress.
            $table->string('sync_batch_id')->nullable();

            // The repository webhook created for this package, which only
            // exists as the fallback for repositories the GitHub App's own
            // webhook does not deliver for. Packages under an installed app
            // leave all three null and are covered account-wide.
            // Whether this package should sync itself when its repository is
            // pushed to. On by default — a package is added to be published,
            // and publishing it late is the surprising behaviour.
            $table->boolean('webhook_enabled')->default(true);

            $table->unsignedBigInteger('webhook_id')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->text('webhook_error')->nullable();

            // When a delivery was last accepted for this package, whichever
            // webhook carried it — the one honest answer to "is auto-sync
            // actually working?".
            $table->timestamp('webhook_received_at')->nullable();

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
