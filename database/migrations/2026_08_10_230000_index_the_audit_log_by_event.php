<?php

use App\Filament\Resources\Activities\Tables\ActivitiesTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make the audit log's Action filter a range scan.
 *
 * The events worth filtering for are the rare ones — `role_granted`,
 * `grant_added`, `deleted` — and rare is exactly what makes the existing
 * `created_at` index useless for them: the list is newest first, so the
 * planner walks back through two years of `updated` rows a page at a time
 * looking for the handful that match. The composite puts the matching rows in
 * the order the page wants them, which is what turns "find twenty
 * role grants" from a scan of the table into twenty rows read.
 *
 * `created_at` keeps its own index: it is what the unfiltered listing sorts by
 * and what `model:prune` walks, and neither can use a composite led by `event`.
 *
 * @see ActivitiesTable
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('activitylog.database_connection'))->table(config('activitylog.table_name'), function (Blueprint $table) {
            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))->table(config('activitylog.table_name'), function (Blueprint $table) {
            $table->dropIndex(['event', 'created_at']);
        });
    }
};
