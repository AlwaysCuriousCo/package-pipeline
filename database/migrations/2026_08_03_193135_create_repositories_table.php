<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('repositories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();

            // The URL slug the repository's Composer endpoints are mounted
            // under (/r/{path}/...). Null only for the default repository,
            // which is served at the site root; Repository::default() is the
            // one place that creates that row.
            $table->string('path')->nullable()->unique();

            $table->text('description')->nullable();

            // Whether unauthenticated Composer clients may read from this
            // repository. Reads on a private repository require a token with
            // access to it.
            $table->boolean('public')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repositories');
    }
};
