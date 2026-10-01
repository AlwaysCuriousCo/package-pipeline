<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            // The entry name the install command gives this repository in a
            // consumer's composer.json. Null derives it, as it always has.
            // Unique, because two repositories sharing one would tell a
            // project to overwrite one with the other.
            $table->string('composer_key', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropUnique(['composer_key']);
            $table->dropColumn('composer_key');
        });
    }
};
