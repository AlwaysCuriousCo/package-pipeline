<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The four page columns that were added to an already-released migration.
     *
     * `page_type`, `page_source`, `page_body_source` and `page_body_path` were
     * written into 2026_08_12_000000_add_public_pages_to_packages_and_repositories
     * after that file had already run on a deployed registry. A migration is
     * recorded by name, so amending one that has run is invisible: a fresh
     * install gets all thirteen page columns and every registry upgraded
     * before the amendment gets nine, and only finds out when saving a package
     * fails on an unknown column. This adds the missing four where they are
     * missing.
     *
     * Guarded per column rather than per table, because both shapes of
     * database are legitimate and both have to end here — the amended file
     * created these on anything installed since, and this must be a no-op
     * there rather than a duplicate-column error. The defaults are the ones
     * the amended migration declares, so a page's meaning does not depend on
     * which of the two paths a registry took to get its columns.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            if (! Schema::hasColumn('packages', 'page_type')) {
                $table->boolean('page_type')->default(true);
            }

            if (! Schema::hasColumn('packages', 'page_source')) {
                $table->boolean('page_source')->default(false);
            }

            if (! Schema::hasColumn('packages', 'page_body_source')) {
                $table->string('page_body_source', 16)->default('auto');
            }

            if (! Schema::hasColumn('packages', 'page_body_path')) {
                $table->string('page_body_path')->nullable();
            }
        });
    }

    /**
     * Deliberately nothing.
     *
     * These columns belong to the migration above, whose own `down()` drops
     * all thirteen of them together. Dropping them here as well would leave
     * that rollback failing on columns it expects to find, on the one database
     * shape this migration exists to repair.
     */
    public function down(): void
    {
        //
    }
};
