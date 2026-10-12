<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            // A hostname of the repository's own — composer.example.com — at
            // whose root it answers as well as under /r/{path}. Null serves it
            // under the path only, as it always has. Unique, because a host is
            // how a request is resolved to a repository.
            $table->string('host', 253)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('repositories', function (Blueprint $table) {
            $table->dropUnique(['host']);
            $table->dropColumn('host');
        });
    }
};
