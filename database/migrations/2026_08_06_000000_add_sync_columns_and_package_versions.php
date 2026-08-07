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
        Schema::table('packages', function (Blueprint $table) {
            // Composer resolves packages by name, so it must be unique.
            $table->unique('name');
            $table->text('token')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('sync_error')->nullable();
        });

        Schema::create('package_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->string('version');
            $table->string('reference');
            $table->boolean('is_dev')->default(false);
            $table->json('metadata');
            $table->timestamps();

            $table->unique(['package_id', 'version']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('package_versions');

        Schema::table('packages', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->dropColumn(['token', 'last_synced_at', 'sync_error']);
        });
    }
};
