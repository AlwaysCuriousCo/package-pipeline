<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The HTTP Basic username a token is configured with.
 *
 * Cosmetic to authentication — the password is the whole credential — but it
 * is what lands in auth.json, so it is what an operator reads when they open
 * that file six months later. Null means "derive it": the owner's email for a
 * personal token, the deploy token's name for a machine one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_tokens', function (Blueprint $table): void {
            $table->string('username')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('access_tokens', function (Blueprint $table): void {
            $table->dropColumn('username');
        });
    }
};
