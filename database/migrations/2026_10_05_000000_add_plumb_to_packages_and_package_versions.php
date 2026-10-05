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
        // One Plumb scan summary — the release it ran against, when, and the
        // four scores — on the package (its newest scan) and on each version
        // Plumb scanned while that version was the latest stable release.
        // JSON rather than columns: nothing sorts or filters by it, and it is
        // always read whole. Null is "Plumb has no scan for this".
        Schema::table('packages', function (Blueprint $table) {
            $table->json('plumb')->nullable();
        });

        Schema::table('package_versions', function (Blueprint $table) {
            $table->json('plumb')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('plumb');
        });

        Schema::table('package_versions', function (Blueprint $table) {
            $table->dropColumn('plumb');
        });
    }
};
