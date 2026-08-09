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
        // Nothing ever read this column. The dashboard charts and the
        // denormalized counters are built from created_at and the foreign
        // keys; token_prefix already answers "which credential fetched it".
        // No screen, query, command or export asked for the address.
        //
        // Which leaves a per-download IP retained forever on the fastest
        // growing table in the schema, earning nothing. A registry someone
        // runs on their own infrastructure should not collect that by
        // default, and dropping the column also drops the addresses already
        // recorded — the part a retention policy could not undo on its own.
        //
        // If abuse investigation ever needs it, add it back deliberately,
        // with a pruning schedule written at the same time.
        Schema::table('downloads', function (Blueprint $table) {
            $table->dropColumn('ip');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Comes back empty; the addresses are gone, and the listener no
        // longer has one to write.
        Schema::table('downloads', function (Blueprint $table) {
            $table->string('ip', 45)->nullable()->after('version');
        });
    }
};
