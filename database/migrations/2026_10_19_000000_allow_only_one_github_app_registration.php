<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One GitHub App registration per registry, enforced by the database.
 *
 * The code already refuses to register a second app, but a race — two
 * registrations submitted at once — could pass that check twice and insert
 * two rows, one of which would then silently shadow the other. A column that
 * is always 1 under a unique index makes the invariant physical: the second
 * insert fails whatever the code did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('github_app_registrations', function (Blueprint $table) {
            $table->boolean('singleton')->default(true);
            $table->unique('singleton');
        });
    }

    public function down(): void
    {
        Schema::table('github_app_registrations', function (Blueprint $table) {
            $table->dropUnique(['singleton']);
            $table->dropColumn('singleton');
        });
    }
};
