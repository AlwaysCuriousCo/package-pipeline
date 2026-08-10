<?php

use Illuminate\Database\Migrations\Migration;
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
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        match (Schema::getConnection()->getDriverName()) {
            // Postgres refuses to widen text to json on its own; the USING
            // clause is what tells it the existing rows are already documents.
            'pgsql' => DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE json USING data::json'),
            'mysql', 'mariadb' => DB::statement('ALTER TABLE notifications MODIFY data JSON NOT NULL'),
            // SQLite stores a json column as text anyway — Laravel's own
            // grammar maps the two to the same declared type — so there is
            // nothing here to convert.
            default => null,
        };
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
