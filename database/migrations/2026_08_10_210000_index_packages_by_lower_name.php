<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An index over `lower(name)`, for the one query that cannot use the plain one.
 *
 * MirrorService::mirrorable() asks "does anything in this installation publish
 * this name?" case-insensitively, and has to: it is the dependency-confusion
 * guard, and the property that makes it a guard is that it inherits no
 * invariant maintained in another file (see the comment there). The index added
 * in 2026_08_10_140100 cannot answer that comparison on any engine whose
 * collation is case-sensitive, so the guard was a full scan of `packages` —
 * and it runs on *both* the metadata and the dist request for every mirrored
 * dependency, which makes a cold install of three hundred packages six hundred
 * scans.
 *
 * An expression index removes the scan without touching the comparison, which
 * is the only acceptable trade here: nothing about the guard's meaning changes,
 * it just stops being linear in the size of the registry.
 *
 * MariaDB is the one engine left out — it has no functional indexes, and the
 * alternative (a stored generated column plus a trigger's worth of surface) is
 * not worth it for a table measured in hundreds. It keeps the scan it already
 * had, which is what every engine had until now.
 */
return new class extends Migration
{
    public function up(): void
    {
        match ($this->driver()) {
            // MySQL 8 wants the expression in its own parentheses; without the
            // inner pair it reads `lower(name)` as a column called `lower`.
            'mysql' => DB::statement('create index packages_lower_name_index on packages ((lower(name)))'),
            'pgsql', 'sqlite' => DB::statement('create index packages_lower_name_index on packages (lower(name))'),
            default => null,
        };
    }

    public function down(): void
    {
        match ($this->driver()) {
            'mysql' => DB::statement('drop index packages_lower_name_index on packages'),
            'pgsql', 'sqlite' => DB::statement('drop index packages_lower_name_index'),
            default => null,
        };
    }

    /** MariaDB answers to the `mysql` driver but lacks functional indexes; route it to `default`. */
    private function driver(): string
    {
        $connection = Schema::getConnection();

        return method_exists($connection, 'isMaria') && $connection->isMaria() ? 'mariadb' : $connection->getDriverName();
    }
};
