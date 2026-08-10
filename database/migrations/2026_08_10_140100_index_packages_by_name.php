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
     * anything here publish this name?".
     *
     * That guard compares case-insensitively and so cannot use this index at
     * all (see MirrorService::mirrorable() for why it has to). This is here for
     * the databases whose default collation makes the comparison exact anyway,
     * and for the plain equality every other registry-wide name lookup is.
     * It costs one small index on a table measured in hundreds.
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
