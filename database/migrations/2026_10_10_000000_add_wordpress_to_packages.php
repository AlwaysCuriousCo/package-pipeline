<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            // Null on every package that is not a plugin or theme, which is
            // every row this migration finds.
            $table->string('wordpress_kind', 32)->nullable();

            // The directory WordPress installs the package into. Unique across
            // the registry rather than per repository: a site has one
            // wp-content/plugins/acme-forms, the update API is asked by slug
            // alone, and the dist URL names nothing else. Nullable unique
            // admits any number of packages without one.
            $table->string('wordpress_slug', 200)->nullable()->unique();
        });

        Schema::table('package_versions', function (Blueprint $table) {
            // The version's WordPress zip and what its header said: path,
            // sha1, size, and the header fields. A column of its own rather
            // than a key in `metadata`, because `metadata` is the composer.json
            // /p2 serves, and the Composer document must not change.
            $table->json('wordpress')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('package_versions', function (Blueprint $table) {
            $table->dropColumn('wordpress');
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropUnique(['wordpress_slug']);
            $table->dropColumn(['wordpress_kind', 'wordpress_slug']);
        });
    }
};
