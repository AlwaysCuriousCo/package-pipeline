<?php

use App\Models\Package;
use Illuminate\Database\Eloquent\Collection;
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
            // The `repository` column beside this one holds the URL as it was
            // typed — https, ssh, git@, bare path, with or without .git and
            // with or without browser chrome on the end. Nothing can look a
            // package up by the path a webhook names without either parsing
            // every row or matching with a leading wildcard, and a leading
            // wildcard uses no index. This is that URL reduced to the one
            // spelling all of its forms agree on, so the lookup is an equality
            // on an indexed column.
            //
            // Nullable and non-unique on purpose: a package published by
            // artifact upload has no VCS URL to derive one from, and two
            // packages may legitimately be published from the same repository.
            $table->string('repository_path')->nullable()->index()->after('repository');
        });

        // Derived through the model rather than by a SQL expression: the parse
        // is a regex over several URL shapes and is provider-dependent (GitHub
        // repositories are exactly owner/repo; GitLab namespaces nest), so a
        // second copy of it here would be a second copy to keep in step.
        //
        // Quietly and without timestamps because this fills in a column
        // derived from one already stored: /p2 cuts its ETag and Last-Modified
        // from `updated_at`, and touching every row would have every Composer
        // client in the fleet refetch every package's metadata for nothing.
        Package::query()
            ->with('source')
            ->chunkById(200, function (Collection $packages): void {
                Package::withoutTimestamps(function () use ($packages): void {
                    foreach ($packages as $package) {
                        $package
                            ->forceFill(['repository_path' => $package->normalizedRepositoryPath()])
                            ->saveQuietly();
                    }
                });
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex(['repository_path']);
            $table->dropColumn('repository_path');
        });
    }
};
