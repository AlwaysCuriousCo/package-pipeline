<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('provider')->index();

            // Only set for self-hosted instances (GitHub Enterprise); null
            // means the provider's public API.
            $table->string('base_url')->nullable();

            // The owner whose repositories this source reaches — a GitHub org
            // or user login. Packages are matched to a source by this value,
            // so one source per owner.
            $table->string('account')->nullable();
            $table->string('account_type')->nullable();

            // Set by the GitHub App install callback. With it we can mint
            // short-lived installation tokens on demand and never store a
            // long-lived credential.
            $table->unsignedBigInteger('installation_id')->nullable();

            // Manual fallback for instances with no GitHub App registered.
            $table->text('token')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->text('connection_error')->nullable();
            $table->timestamps();

            // A given installation may only be claimed by one source, so a
            // second "Connect" onto the same installation is rejected by the
            // database rather than silently splitting packages across rows.
            $table->unique(['provider', 'installation_id']);
            $table->unique(['provider', 'account']);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->foreignId('source_id')
                ->nullable()
                ->after('id')
                // Deleting a source leaves its packages in place; they fall
                // back to their own token or GITHUB_TOKEN until relinked.
                ->constrained()
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_id');
        });

        Schema::dropIfExists('sources');
    }
};
