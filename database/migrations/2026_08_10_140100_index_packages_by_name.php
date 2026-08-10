<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The existing unique index is (repository_id, name), which cannot answer
     * a question that names no repository — and mirroring asks exactly that,
     * on every request for a package this registry does not publish: "does
     * anything here publish this name?". Left unindexed that is a full scan of
     * `packages` in front of every mirrored lookup, and it is the query that
     * decides whether an upstream may answer at all, so it runs before
     * anything else can be skipped.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex(['name']);
        });
    }
};
