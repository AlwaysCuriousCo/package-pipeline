<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_versions', function (Blueprint $table) {
            // A null `released_at` has meant two different things: "not asked
            // yet", which the next sync should fix, and "asked, and the
            // provider had no date for this commit" — which no sync will ever
            // fix, because the answer is not coming. Told apart only by the
            // null, the second case was indistinguishable from an incomplete
            // row, so PackageSynchronizer::unchanged() judged it changed and
            // re-imported it — composer.json, commit, and the full zipball —
            // on every sync, forever.
            //
            // Default false is right for existing rows either way: one that
            // genuinely has no date upstream is re-imported once more and
            // settles, and one that was never asked still needs asking.
            $table->boolean('released_at_unknown')->default(false)->after('released_at');
        });
    }

    public function down(): void
    {
        Schema::table('package_versions', function (Blueprint $table) {
            $table->dropColumn('released_at_unknown');
        });
    }
};
