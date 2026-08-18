<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The public page a package or repository can publish for a person rather
     * than for Composer.
     *
     * Everything here is off by default and stays off through the upgrade:
     * a registry that publishes private packages must not start serving pages
     * describing them because it was migrated. Enabling one is a decision an
     * admin makes per package, which is why the switch is a column and not a
     * setting.
     */
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            // The switch itself. Indexed because the public routes look a
            // package up by name and then ask this, and a registry with a
            // handful of pages among thousands of packages should not read a
            // row to find out it has no page.
            $table->boolean('page_enabled')->default(false)->index();

            // Which archives the page offers, as App\Enums\PageDownloads:
            // none, the latest release alone, or every stored version. Stored
            // as a string rather than a boolean pair because "latest only" is
            // the interesting middle — a page that hands out one current
            // artifact without publishing the release history with it.
            $table->string('page_downloads', 16)->default('none');

            // Whether the page prints the two `composer config` / `composer
            // require` lines. On by default: a page that names a package
            // without saying how to install it is the one thing every visitor
            // came for.
            $table->boolean('page_install')->default(true);

            // Whether the version history table is rendered.
            $table->boolean('page_versions')->default(true);

            // The two facts in the page's header that are not the package's
            // own description. `page_type` is what Composer calls it —
            // library, project, metapackage — which is useful and harmless.
            //
            // `page_source` is the repository URL, and it defaults to *off*
            // because it is the one field on a page that names infrastructure
            // rather than describing a package: on a private package it
            // publishes the organisation and repository name to anyone who
            // opens the page. Switching it on is a decision, and a reasonable
            // one for anything open source.
            $table->boolean('page_type')->default(true);
            $table->boolean('page_source')->default(false);

            // Where the page's body comes from, as App\Enums\PageBodySource:
            // the repository's page file or README, one file the admin names,
            // or markdown written in the panel. Stated rather than inferred
            // from whether the textarea below is empty, so that "publish the
            // README again" is a choice rather than the act of deleting what
            // was typed.
            $table->string('page_body_source', 16)->default('auto');

            // Which file, when the source above is `file`. Relative to the
            // package's own directory, so a monorepo package names its own
            // docs/registry.md rather than the repository root's.
            $table->string('page_body_path')->nullable();

            // Markdown written in the panel. The escape hatch for a package
            // whose README is written for contributors rather than consumers,
            // and the only body available for a package published by artifact
            // upload, which has no repository to read one from.
            $table->longText('page_body')->nullable();

            // The image social platforms show when the page's URL is pasted
            // into a post — an absolute URL, because that is what an og:image
            // has to be and because the file usually lives wherever the
            // project already publishes its logo. Empty falls back to the
            // registry-wide default in config/registry.php, so an
            // installation sets one image once rather than per package.
            $table->string('page_image')->nullable();

            // The markdown last read out of the repository, and which file it
            // came from — `package-page.md` or a README. Stored rather than
            // fetched per request: rendering a page must not depend on
            // GitHub's API being reachable, and must not spend a request of
            // the installation's rate limit per visitor.
            $table->longText('page_source_body')->nullable();
            $table->string('page_source_path')->nullable();
            $table->timestamp('page_source_synced_at')->nullable();
        });

        Schema::table('repositories', function (Blueprint $table) {
            // The repository's own landing page, served at the same URL its
            // Composer endpoints hang off — "/" for the default repository,
            // "/r/{path}" for a named one. Off by default for the same reason
            // the package switch is.
            $table->boolean('page_enabled')->default(false);

            // Markdown shown above the package list. There is no repository
            // equivalent of a README to read, so this is the only body.
            $table->longText('page_body')->nullable();

            // As on packages: the social preview image for this repository's
            // landing page, falling back to the registry-wide default.
            $table->string('page_image')->nullable();

            // Whether the page lists the packages this repository serves.
            // Off leaves a page that describes the repository and prints the
            // one `composer config` line to point a project at it, which is
            // what a private repository can publish without naming what it
            // holds.
            $table->boolean('page_lists_packages')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Lossy in one direction only: the page bodies written in the panel are
     * dropped with the columns. The repository-sourced ones are not a loss —
     * the next sync reads them again from the file they came from.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn([
                'page_enabled',
                'page_downloads',
                'page_install',
                'page_versions',
                'page_type',
                'page_source',
                'page_body_source',
                'page_body_path',
                'page_body',
                'page_image',
                'page_source_body',
                'page_source_path',
                'page_source_synced_at',
            ]);
        });

        Schema::table('repositories', function (Blueprint $table) {
            $table->dropColumn(['page_enabled', 'page_body', 'page_image', 'page_lists_packages']);
        });
    }
};
