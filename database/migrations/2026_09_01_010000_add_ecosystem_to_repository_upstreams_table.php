<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repository_upstreams', function (Blueprint $table) {
            // Which protocol this upstream speaks — and therefore which of
            // the repository's serving surfaces consults it. Defaulted rather
            // than backfilled: every existing upstream is a Composer one.
            $table->string('ecosystem', 16)->default('composer');
        });
    }

    public function down(): void
    {
        Schema::table('repository_upstreams', function (Blueprint $table) {
            $table->dropColumn('ecosystem');
        });
    }
};
