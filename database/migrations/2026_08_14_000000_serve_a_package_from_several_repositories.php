<?php

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
        // Which repositories serve a package. Until now that was one column on
        // the package — `packages.repository_id` — and one package could be
        // reached under exactly one mount. Publishing the same library to an
        // internal repository and a public one meant two package rows syncing
        // the same VCS URL twice, with two sets of versions, archives, download
        // counters and webhooks to keep in step.
        //
        // `packages.repository_id` stays, and stays meaningful: it is the
        // package's *home* — where it was created, what its canonical page URL
        // is cut from, and which repository a reserved vendor is measured
        // against. This table is the full set, home included, so that every
        // query that serves a repository ("the packages of repository R") can
        // read one relation and get both.
        Schema::create('package_repository', function (Blueprint $table) {
            $table->id();

            // Cascading from both sides, like every other pivot here: a
            // deleted package is served nowhere, and a deleted repository
            // serves nothing. Note that repositories are restrictOnDelete from
            // `packages` — a repository holding packages cannot be deleted at
            // all — so this cascade only ever fires for the shares.
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('repository_id')->constrained()->cascadeOnDelete();

            // The package's name, copied. It is here for the unique index
            // below and for nothing else: a repository cannot serve two
            // packages under one name, because the whole Composer surface —
            // /p2, /dist, the page, the upload endpoint — resolves a name
            // within a repository and would have no way to choose.
            //
            // `packages.name` used to carry that guarantee on its own through
            // the (repository_id, name) unique index, which is still there and
            // still right for the home repository. It cannot see a share, so
            // the guarantee moves here, where it covers both.
            //
            // Kept in step by Package::booted(), which rewrites these rows
            // whenever the name changes. Named `package_name` rather than
            // `name` because `$repository->packages()->where('name', ...)` is
            // written all over the serving paths, and a bare `name` on the
            // pivot would make every one of those ambiguous.
            $table->string('package_name');

            $table->timestamps();

            $table->unique(['package_id', 'repository_id']);
            $table->unique(['repository_id', 'package_name']);
        });

        // Every package that exists is served where it always was. After this
        // the pivot is the whole truth about serving, and the column beside it
        // is only the home half of it.
        DB::table('packages')
            ->orderBy('id')
            ->select(['id', 'repository_id', 'name'])
            ->chunk(500, function ($packages): void {
                DB::table('package_repository')->insert($packages->map(fn ($package): array => [
                    'package_id' => $package->id,
                    'repository_id' => $package->repository_id,
                    'package_name' => $package->name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all());
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The shares are the only thing lost, and there is nowhere to put
        // them: a package can hold one repository_id and it already does.
        Schema::dropIfExists('package_repository');
    }
};
