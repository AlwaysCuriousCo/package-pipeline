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
            // Every /p2 request — the one endpoint a `composer update`
            // hammers — asks this table for `package_id = ? and is_dev = ?`,
            // and so does the aggregate that decides whether the response can
            // be a 304. Neither existing index answers that: the unique
            // (package_id, version) stops at the prefix, and the lone `order`
            // index below leads with the wrong column entirely.
            //
            // The two equalities are what this buys; `order` trails them so
            // the sort key travels with the rows the seek finds. The document
            // is ordered by an expression over `order` and broken by version,
            // so the planner still sorts — but over one package's versions,
            // which is nothing beside decoding their `metadata` columns.
            $table->index(['package_id', 'is_dev', 'order']);

            // Dropped as redundant rather than left to rot: nothing filters or
            // sorts by `order` alone — every reader of it has narrowed to one
            // package first — so all this index did was cost a write on a
            // table that gains a row per version per sync.
            $table->dropIndex(['order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('package_versions', function (Blueprint $table) {
            $table->index('order');
            $table->dropIndex(['package_id', 'is_dev', 'order']);
        });
    }
};
