<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repository_upstreams', function (Blueprint $table) {
            // The HTTP Basic username sent with the token. Null keeps every
            // existing upstream sending what it always has.
            $table->string('username')->nullable();
            // Whether versions cached from this upstream outlive the upstream
            // withdrawing them — and survive mirror:prune.
            $table->boolean('keep_versions')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('repository_upstreams', function (Blueprint $table) {
            $table->dropColumn(['username', 'keep_versions']);
        });
    }
};
