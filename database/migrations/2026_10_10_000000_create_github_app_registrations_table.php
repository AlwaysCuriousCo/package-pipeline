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
        // The GitHub App this registry authenticates as, when it was registered
        // from the panel rather than configured in the environment. One row at
        // most; the environment wins whenever GITHUB_APP_ID is set.
        Schema::create('github_app_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('app_id');
            $table->string('slug');
            $table->string('name');

            // The account that owns the app on GitHub — a user or organisation
            // login, or an enterprise slug — which is where its settings live.
            $table->string('owner');
            $table->string('owner_type');
            $table->string('html_url');

            // Both encrypted casts on the model. GitHub only generates a
            // webhook secret for an app that was created with its webhook on.
            $table->text('private_key');
            $table->text('webhook_secret')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('github_app_registrations');
    }
};
