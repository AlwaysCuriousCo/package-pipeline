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
        // Login providers configured at runtime — an OIDC issuer, GitHub,
        // Google, GitLab — each carrying its own OAuth client and rules for
        // who may register through it.
        Schema::create('authentication_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('provider');
            $table->string('client_id');
            $table->text('client_secret');

            // Only OIDC needs one; the named providers know their endpoints.
            $table->string('discovery_url')->nullable();

            $table->boolean('active')->default(true);

            // Whether an unknown identity may create an account, and if so,
            // which email domains qualify (empty = any) and what role the
            // fresh account starts with.
            $table->boolean('allow_registration')->default(true);
            $table->json('allowed_domains')->nullable();
            $table->string('default_role')->nullable();

            $table->timestamps();
        });

        // The external identity a user signs in as. Lives here rather than in
        // the users create migration because the FK needs this table first.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('authentication_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_id')->nullable();

            $table->unique(['authentication_source_id', 'external_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('authentication_source_id');
            $table->dropColumn('external_id');
        });

        Schema::dropIfExists('authentication_sources');
    }
};
