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
            // Where inside the repository this package's composer.json lives,
            // so one repository can publish several packages — the layout an
            // organisation ends up with the moment it keeps its packages in a
            // monorepo. Empty means the repository root, which is what every
            // package published before this migration was.
            //
            // Empty string rather than null, and that is the whole reason the
            // column is not nullable: it is part of the unique index below,
            // and no SQL engine considers two nulls equal. A null "no
            // subdirectory" would therefore let the same repository URL be
            // added twice to one Composer repository — silently undoing the
            // guarantee this index has always made — while an empty string
            // collides exactly as it should.
            $table->string('subdirectory')->default('')->after('repository_path');
        });

        Schema::table('packages', function (Blueprint $table) {
            // Widened rather than dropped: a repository URL is still claimed
            // once per Composer repository, but now per subdirectory within
            // it. Existing rows all carry the '' the column defaults to, so
            // the new index constrains them exactly as the old one did and
            // cannot reject data that already fits.
            $table->dropUnique(['repository_id', 'repository']);
            $table->unique(['repository_id', 'repository', 'subdirectory']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropUnique(['repository_id', 'repository', 'subdirectory']);
            $table->unique(['repository_id', 'repository']);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('subdirectory');
        });
    }
};
