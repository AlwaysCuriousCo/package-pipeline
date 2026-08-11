<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop `mirrored_packages(name)`, which nothing reads.
 *
 * It was added for a query that was never written: the idea was that a
 * repository with several upstreams would find every candidate row for a name
 * in one go. It does not — MirrorService walks the upstreams in the operator's
 * order and asks about one at a time, because first-match-wins is the rule it
 * implements and a set of rows cannot express an order. Every reader therefore
 * leads with `upstream_id`, which is the unique index's first column, and the
 * two that do not (`mirror:prune`, the metrics gauge) read `used_at` or the
 * whole table.
 *
 * So it was pure write cost on the fastest-growing table the mirror has: a row
 * per name per upstream, created by whatever a Composer client happens to
 * resolve and rewritten on every revalidation. `mirrored_archives` never had
 * the equivalent, which is the shape both tables should have had.
 *
 * The create migration is corrected in place as well, so a new install never
 * builds it — which is why the drop is conditional rather than unguarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('mirrored_packages', ['name'])) {
            return;
        }

        Schema::table('mirrored_packages', function (Blueprint $table) {
            $table->dropIndex(['name']);
        });
    }

    /**
     * Not reversible, and nothing to reverse: the index answered no query, so
     * rebuilding it would restore a write cost and no read.
     */
    public function down(): void {}
};
