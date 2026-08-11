<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail: who changed what, and when.
 *
 * spatie/laravel-activitylog ships this as three migrations because its own
 * schema grew over four major versions; a registry adopting it today only
 * ever needs the final shape, so they are collapsed into one.
 *
 * The table is append-only in practice — nothing in the app updates or
 * deletes a row — which is why App\Models\Activity gives it a retention
 * policy `model:prune` can enforce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('activitylog.database_connection'))->create(config('activitylog.table_name'), function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');

            // What was changed, and who changed it. Both nullable: a subject
            // may be deleted afterwards, and a change made by the console or
            // a webhook has no signed-in causer at all.
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');

            // The attribute diff. Every model states an explicit allowlist of
            // what may land here — see App\Models\Concerns\LogsAuditableChanges
            // — so an encrypted cast can never be written into it.
            $table->json('properties')->nullable();

            // Groups the activities written inside one operation, so a change
            // that touches several records reads as one event.
            $table->uuid('batch_uuid')->nullable();

            $table->timestamps();

            $table->index('log_name');

            // The panel's default listing is newest first, and pruning walks
            // the same column.
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))->dropIfExists(config('activitylog.table_name'));
    }
};
