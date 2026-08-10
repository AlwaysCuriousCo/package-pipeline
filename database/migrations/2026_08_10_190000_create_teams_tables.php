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
        // A group of people that holds grants, so that onboarding is adding
        // somebody to a team rather than re-granting whatever the last hire
        // was granted. The per-user grants beside this are untouched and keep
        // working exactly as they did: a user's reach is their own grants plus
        // their teams', never one instead of the other.
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Membership. Cascading from both sides: a deleted team grants
        // nothing, and a deleted account is not a member of anything — and a
        // stale row on either side would be a grant nobody can see in the
        // panel and nothing would ever clear.
        Schema::create('team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->unique(['team_id', 'user_id']);
        });

        // The grants themselves, mirroring package_user and repository_user
        // column for column — because they mean exactly the same thing, and
        // the visibility scope unions the two together.
        Schema::create('package_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->unique(['package_id', 'team_id']);
        });

        Schema::create('repository_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->unique(['repository_id', 'team_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repository_team');
        Schema::dropIfExists('package_team');
        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
