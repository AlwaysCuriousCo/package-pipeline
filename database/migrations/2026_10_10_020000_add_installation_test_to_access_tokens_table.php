<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the short-lived token an installation test authenticates with.
 *
 * The test makes the requests Composer makes, through the registry's public
 * address, and so arrives as an ordinary authenticated download. The flag is
 * how the dist endpoint tells it from one: a test proves the registry works,
 * and counting it would put an install in the analytics that nobody made.
 * Such a token lives for one run and is deleted when the run ends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_tokens', function (Blueprint $table) {
            $table->boolean('installation_test')->default(false)->after('username');
        });
    }

    public function down(): void
    {
        Schema::table('access_tokens', function (Blueprint $table) {
            $table->dropColumn('installation_test');
        });
    }
};
