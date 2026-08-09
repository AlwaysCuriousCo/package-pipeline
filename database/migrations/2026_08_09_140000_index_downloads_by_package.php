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
        // `foreignId()->constrained()` is not an index. InnoDB happens to
        // create one behind every foreign key, so on MySQL these lookups were
        // covered by accident — but Postgres indexes only the referenced side,
        // and SQLite (this app's default connection) indexes neither. Two of
        // the three supported databases were full-scanning the one table in
        // the schema that grows without bound and is never pruned.
        Schema::table('downloads', function (Blueprint $table) {
            // Package first, date second, because every reader narrows to a
            // package (or a set of them) and then to a window: the dashboard
            // and package charts filter `package_id` with `created_at >=`, and
            // downloads:recalculate counts per package with no date at all —
            // which the leading column alone already answers. The reverse
            // order would serve only the second of those.
            $table->index(['package_id', 'created_at']);

            // downloads:recalculate runs the same correlated count per version
            // as it does per package, and that one has no prefix to borrow.
            $table->index('package_version_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->dropIndex(['package_id', 'created_at']);
            $table->dropIndex(['package_version_id']);
        });
    }
};
