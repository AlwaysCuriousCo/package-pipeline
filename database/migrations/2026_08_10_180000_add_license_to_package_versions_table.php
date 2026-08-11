<?php

use App\Models\PackageVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('package_versions', function (Blueprint $table) {
            // What the version's composer.json declares, as one SPDX
            // expression — "MIT", or "MIT OR Apache-2.0" for a manifest that
            // lists several. Null when it declares none.
            //
            // Derived from `metadata`, which already holds it, and stored
            // beside it for the same reason `repository_path` is stored beside
            // `repository`: every question licensing is asked here is asked of
            // the whole registry at once — which licenses are in use, which
            // versions declare none, what goes in an SBOM — and answering any
            // of them from `metadata` means decoding a whole composer.json per
            // version to read one field of it. As a column it is an indexed
            // group-by; as JSON it is a full scan the panel would render
            // behind.
            //
            // The list is folded to an expression rather than kept as an array
            // because Composer's own semantics for several licenses is a
            // choice between them, which is exactly what "A OR B" means — and
            // because it is the one shape the report, the panel filter and the
            // CycloneDX export all want.
            $table->string('license')->nullable()->index()->after('metadata');
        });

        // Bulk per distinct license rather than a save per row: the table
        // gains a row per version per package and this runs on every existing
        // one. Grouping the chunk's ids by the value they resolve to turns
        // 500 updates into as many as there are licences in that chunk, which
        // in practice is a handful.
        //
        // Through the query builder so `updated_at` is left alone: /p2 cuts
        // its ETag and Last-Modified from it, and touching every version would
        // have every Composer client in the fleet refetch every package's
        // metadata to learn nothing.
        PackageVersion::query()
            ->select(['id', 'metadata'])
            ->chunkById(500, function (Collection $versions): void {
                $byLicense = $versions->groupBy(
                    fn (PackageVersion $version): string => (string) PackageVersion::licenseExpression($version->licenses()),
                );

                foreach ($byLicense as $license => $group) {
                    DB::table('package_versions')
                        ->whereIn('id', $group->modelKeys())
                        ->update(['license' => $license === '' ? null : $license]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('package_versions', function (Blueprint $table) {
            $table->dropIndex(['license']);
            $table->dropColumn('license');
        });
    }
};
