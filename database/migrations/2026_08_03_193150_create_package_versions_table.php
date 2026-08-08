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
        Schema::create('package_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->string('version');
            $table->string('reference');
            $table->boolean('is_dev')->default(false);

            // Where this version's zip lives on the dist disk, and the sha1
            // Composer verifies downloads against. Null only for a row whose
            // archive has not been stored yet; the next sync backfills it.
            $table->string('archive_path')->nullable();
            $table->string('shasum', 40)->nullable();

            // Nullable because a ref may resolve before its date is known; the
            // next sync backfills it, since a null date is what looks stale.
            $table->timestamp('released_at')->nullable()->index();
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
    }
};
