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
        Schema::create('repository_upstreams', function (Blueprint $table) {
            $table->id();

            // The Composer repository that mirrors through this upstream.
            // Deleting the repository takes its upstreams with it; the cached
            // metadata and archives hanging off them go the same way.
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();

            // A label for the admin. The URL is the identity.
            $table->string('name');

            // The root of another Composer v2 repository — packagist.org's
            // mirror, a corporate proxy, or another installation of this app.
            // Stored without a trailing slash so the same upstream typed two
            // ways cannot be added twice past the unique index below.
            $table->string('url');

            // HTTP Basic credential for an upstream that needs one, which is
            // the case whenever the upstream is somebody's private registry
            // rather than packagist.org. Encrypted, like every other stored
            // provider credential.
            $table->text('token')->nullable();

            // Off is not the same as deleted: an operator turning mirroring off
            // to diagnose a resolution problem should not lose the cache with
            // it, and the rows keep answering `mirror:prune` afterwards.
            $table->boolean('enabled')->default(true);

            // Upstreams are consulted in this order and the first one that
            // answers wins, so the order has to be an operator's decision
            // rather than the insertion order it happened to get.
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            // One repository cannot usefully list the same upstream twice: the
            // second copy would only ever be reached after the first had
            // already answered or 404ed for the same name.
            $table->unique(['repository_id', 'url']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('repository_upstreams');
    }
};
