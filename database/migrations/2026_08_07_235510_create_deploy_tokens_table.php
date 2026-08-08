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
        // A deploy token is a machine principal — a CI system, a build box —
        // that owns an access token without owning a user account. Its reach
        // is the union of the pivots below; holding no grants at all means
        // "every package".
        Schema::create('deploy_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('deploy_token_package', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deploy_token_id')->constrained()->cascadeOnDelete();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();

            $table->unique(['deploy_token_id', 'package_id']);
        });

        Schema::create('deploy_token_repository', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deploy_token_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();

            $table->unique(['deploy_token_id', 'repository_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deploy_token_repository');
        Schema::dropIfExists('deploy_token_package');
        Schema::dropIfExists('deploy_tokens');
    }
};
