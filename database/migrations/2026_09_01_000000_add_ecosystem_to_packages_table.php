<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            // Which protocol surface serves this package. Defaulted rather
            // than backfilled: every existing row predates any ecosystem but
            // Composer's.
            $table->string('ecosystem', 16)->default('composer')->index();
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('ecosystem');
        });
    }
};
