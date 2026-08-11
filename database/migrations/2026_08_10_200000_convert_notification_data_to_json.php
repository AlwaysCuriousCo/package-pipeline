<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs the `notifications.data` column on registries created before it was
 * declared json.
 *
 * The create migration was corrected in place, which fixes every new install
 * and reaches none of the existing ones: a database that already ran
 * `0001_01_01_000003` never runs it again, so it keeps the text column and
 * every panel page that renders the notification bell answers 500 on
 * PostgreSQL — Filament filters those rows with `data->format`, and the `->>`
 * that compiles to has no meaning against text.
 *
 * Idempotent by construction rather than by inspection: casting json to json
 * is a no-op on both engines that care, so a fresh install passes through this
 * harmlessly and there is no schema-introspection branch to be wrong about.
 *
 * What it is not idempotent against is a row the cast cannot read, which is why
 * guardUnreadableRows() runs first.
 */
return new class extends Migration
{
    /**
     * How many offending ids the failure names before it stops. Enough to see
     * the shape of the problem; short of pasting a whole table into a terminal.
     */
    private const REPORTED = 20;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // SQLite stores a json column as text anyway — Laravel's own grammar
        // maps the two to the same declared type — so there is nothing here to
        // convert, and nothing to read the table for either.
        if (! in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
            return;
        }

        $this->guardUnreadableRows();

        match ($driver) {
            // Postgres refuses to widen text to json on its own; the USING
            // clause is what tells it the existing rows are already documents.
            'pgsql' => DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE json USING data::json'),
            default => DB::statement('ALTER TABLE notifications MODIFY data JSON NOT NULL'),
        };
    }

    /**
     * Refuse the conversion while any row would abort it, and say which.
     *
     * The `USING data::json` and the `MODIFY ... JSON NOT NULL` are both
     * all-or-nothing: one row of text that is not a document and the statement
     * errors out, with an engine message that quotes a fragment of the value and
     * nothing that would let anyone find the row. Laravel is the only writer
     * here and it always writes a document, so this should never fire — but
     * what makes it worth the query is where the failure lands. This migration
     * sits in the middle of a batch, so an unhandled abort leaves everything
     * before it applied and everything after it not, which is the one deploy
     * state that needs an operator to understand it immediately.
     *
     * Checked in PHP rather than with an engine predicate because the engines
     * that need checking are exactly the ones with no predicate to use: the
     * column is still text, so MySQL's `json_valid()` is the only one on offer
     * and Postgres has nothing before 16. The table is pruned at ninety days,
     * so this is one scan of a bounded table on the deploy that converts it.
     *
     * Public because it is the precondition rather than an implementation
     * detail, and because `up()` returns before reaching it on the engine the
     * suite runs against.
     *
     * @throws RuntimeException
     */
    public function guardUnreadableRows(): void
    {
        $offending = [];

        DB::table('notifications')
            ->select(['id', 'data'])
            ->orderBy('id')
            ->chunkById(500, function (Collection $rows) use (&$offending): void {
                foreach ($rows as $row) {
                    if (! json_validate((string) $row->data)) {
                        $offending[] = (string) $row->id;
                    }
                }
            });

        if ($offending === []) {
            return;
        }

        $named = array_slice($offending, 0, self::REPORTED);
        $rest = count($offending) - count($named);

        throw new RuntimeException(
            'notifications.data cannot be converted to json: '.count($offending).' row(s) do not hold a '
            .'JSON document, and the conversion would abort partway through this migration batch. Nothing '
            .'has been changed. Delete or repair them and migrate again — they are panel bell entries, so '
            .'deleting them costs nothing but the notification itself. Ids: '.implode(', ', $named)
            .($rest > 0 ? " (and {$rest} more)" : '')
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        match (Schema::getConnection()->getDriverName()) {
            'pgsql' => DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text'),
            'mysql', 'mariadb' => DB::statement('ALTER TABLE notifications MODIFY data TEXT NOT NULL'),
            default => null,
        };
    }
};
