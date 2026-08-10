<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remember what `downloads:prune` deleted, so retention does not quietly
 * rewrite history.
 *
 * `total_downloads` is a lifetime figure, incremented as each zip goes out,
 * and `downloads:recalculate` rebuilds it by counting the raw rows. Those two
 * agree only while every row ever written is still there — the moment
 * anything is pruned, a recalculate would silently redefine "total downloads"
 * as "downloads since the retention window opened" and knock a popular
 * package's count down by an order of magnitude.
 *
 * So the pruner counts what it removes before removing it, and the counter
 * becomes this plus whatever rows remain. That keeps both properties: the
 * table stops growing forever, and the recovery tool still recovers the real
 * number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedBigInteger('pruned_downloads')->default(0)->after('total_downloads');
        });

        Schema::table('package_versions', function (Blueprint $table) {
            $table->unsignedBigInteger('pruned_downloads')->default(0)->after('total_downloads');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('pruned_downloads');
        });

        Schema::table('package_versions', function (Blueprint $table) {
            $table->dropColumn('pruned_downloads');
        });
    }
};
