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
        Schema::table('packages', function (Blueprint $table) {
            // Composer spells this one field two ways — `true` for "do not use
            // this any more" and a package name for "use that instead" — and
            // both have to survive a round trip. Two columns rather than a
            // string overloaded with an empty-means-something case, so that
            // "which packages are abandoned?" stays an honest boolean query.
            $table->boolean('abandoned')->default(false);

            // Only meaningful while `abandoned` is true. Not a foreign key: the
            // replacement is frequently a package this registry does not serve
            // (a public one on packagist.org, or one that has not been imported
            // yet), and refusing to record that would defeat the point.
            $table->string('replacement_package')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['abandoned', 'replacement_package']);
        });
    }
};
