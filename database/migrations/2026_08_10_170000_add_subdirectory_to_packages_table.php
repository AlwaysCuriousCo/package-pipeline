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
        Schema::table('packages', function (Blueprint $table) {
            // Where inside the repository this package's composer.json lives,
            // so one repository can publish several packages — the layout an
            // organisation ends up with the moment it keeps its packages in a
            // monorepo. Empty means the repository root, which is what every
            // package published before this migration was.
            //
            // Empty string rather than null, and that is the whole reason the
            // column is not nullable: it is part of the unique index below,
            // and no SQL engine considers two nulls equal. A null "no
            // subdirectory" would therefore let the same repository URL be
            // added twice to one Composer repository — silently undoing the
            // guarantee this index has always made — while an empty string
            // collides exactly as it should.
            $table->string('subdirectory')->default('')->after('repository_path');
        });

        Schema::table('packages', function (Blueprint $table) {
            // Widened rather than dropped: a repository URL is still claimed
            // once per Composer repository, but now per subdirectory within
            // it. Existing rows all carry the '' the column defaults to, so
            // the new index constrains them exactly as the old one did and
            // cannot reject data that already fits.
            $table->dropUnique(['repository_id', 'repository']);
            $table->unique(['repository_id', 'repository', 'subdirectory']);
        });
    }

    /**
     * Reverse the migrations, or refuse before changing anything.
     *
     * The narrow index this puts back is the one the widening existed to
     * remove, so it cannot be re-added once any repository URL publishes more
     * than one package — that is not an edge case, it is the definition of the
     * feature. Left to find out for itself, `down()` dropped the wide index
     * and then failed adding the narrow one, which on an engine with
     * transactional DDL rolls back cleanly and on MySQL does not: the drop has
     * committed, the add has not, and `packages` is left with neither
     * constraint. That is worse than either end of this migration, and it is
     * why the question is asked first rather than caught after.
     *
     * Refusing does not undo the damage `migrate:rollback` has already done by
     * the time it reaches here — the migrations after this one in the batch go
     * first, and several of them are lossy — so the message says so. This
     * release is roll-forward only; see the CHANGELOG.
     */
    public function down(): void
    {
        $shared = DB::table('packages')
            ->select('repository_id', 'repository')
            ->groupBy('repository_id', 'repository')
            ->havingRaw('count(*) > 1')
            ->get();

        throw_if($shared->isNotEmpty(), new RuntimeException(sprintf(
            'Cannot reverse this migration: %d repository URL%s in this registry publish%s more than one '
            .'package, and the unique index this would restore forbids exactly that. Nothing was changed '
            .'here — but the migrations after this one in the batch have already been rolled back, and '
            .'some of them drop data (teams and every grant they held, per-version licenses). This '
            .'release is roll-forward only: restore the backup taken before the upgrade. To go back '
            .'deliberately instead, first delete or move the extra packages so that each repository URL '
            .'publishes one.',
            $shared->count(),
            $shared->count() === 1 ? '' : 's',
            $shared->count() === 1 ? 'es' : '',
        )));

        Schema::table('packages', function (Blueprint $table) {
            $table->dropUnique(['repository_id', 'repository', 'subdirectory']);
            $table->unique(['repository_id', 'repository']);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('subdirectory');
        });
    }
};
