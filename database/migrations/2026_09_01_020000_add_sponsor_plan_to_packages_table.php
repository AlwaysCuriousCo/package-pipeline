<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            // The plan whose prices the package page offers as sponsorship.
            // No database-level constraint: plans soft-delete, so the FK
            // would never fire — and adding one makes SQLite recreate the
            // table, which loses the lower(name) expression index.
            $table->unsignedBigInteger('sponsor_plan_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('sponsor_plan_id');
        });
    }
};
