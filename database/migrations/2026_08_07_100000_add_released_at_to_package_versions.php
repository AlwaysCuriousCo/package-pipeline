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
        Schema::table('package_versions', function (Blueprint $table) {
            // Nullable so existing rows survive the migration; the next sync
            // backfills them, since a null date is what makes a ref look stale.
            $table->timestamp('released_at')->nullable()->after('is_dev')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('package_versions', function (Blueprint $table) {
            $table->dropIndex(['released_at']);
            $table->dropColumn('released_at');
        });
    }
};
