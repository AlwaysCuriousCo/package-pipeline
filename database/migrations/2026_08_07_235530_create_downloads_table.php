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
        // One row per dist download — the raw feed behind the dashboard's
        // chart and the denormalized total_downloads counters.
        Schema::create('downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();

            // The row outlives the version: pruned branches and replaced tags
            // null the FK while the version string keeps history readable.
            $table->foreignId('package_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('version');

            $table->string('ip', 45)->nullable();

            // Which credential fetched it — the prefix, since that is what
            // token listings show and it stays meaningful after revocation.
            $table->string('token_prefix', 16)->nullable();

            $table->timestamp('created_at')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('downloads');
    }
};
