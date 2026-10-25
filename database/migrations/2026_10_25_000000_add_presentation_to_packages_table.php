<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Presentation Mode: a slide deck that replaces a package's public page.
     *
     * Off by default and through the upgrade, for the reason every page column
     * is: what a stranger sees is a decision made per package. The switch is a
     * column; how the deck looks is one JSON document beside it, because those
     * options are only ever read back whole and never queried.
     *
     * @see https://github.com/AlwaysCuriousCo/package-pipeline/issues/55
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->boolean('page_presentation')->default(false);

            // Where the deck comes from and how it is shown — see
            // Package::presentationOptions() for the keys and their defaults.
            $table->json('page_presentation_options')->nullable();

            // Markdown pasted into the panel, for a repository that has no
            // deck file — or a package that has no repository at all.
            $table->longText('page_presentation_body')->nullable();

            // The deck file as last read out of the repository, at sync time,
            // so that a visitor's request never waits on the provider. The
            // same bargain as page_source_body.
            $table->longText('page_presentation_source_body')->nullable();
            $table->string('page_presentation_source_path')->nullable();
            $table->timestamp('page_presentation_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn([
                'page_presentation',
                'page_presentation_options',
                'page_presentation_body',
                'page_presentation_source_body',
                'page_presentation_source_path',
                'page_presentation_synced_at',
            ]);
        });
    }
};
