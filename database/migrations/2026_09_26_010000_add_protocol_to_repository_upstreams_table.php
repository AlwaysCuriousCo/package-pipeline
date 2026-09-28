<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repository_upstreams', function (Blueprint $table) {
            // Which Composer protocol to read the upstream with: 'v2', 'v1',
            // or null to detect it from packages.json.
            $table->string('protocol', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('repository_upstreams', function (Blueprint $table) {
            $table->dropColumn('protocol');
        });
    }
};
