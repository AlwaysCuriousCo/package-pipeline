<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional Composer repository an outgoing webhook is confined to.
 *
 * The table was created installation-wide on the reasoning that what an
 * endpoint subscribes to are facts about the registry, and a consumer wanting
 * one package can filter the payload on its own side. That holds for a registry
 * with one audience. It does not hold for one serving several: every delivery
 * carries a private Composer name, the repository it is mounted at and the VCS
 * URL behind it, so an endpoint one team configured was told about every other
 * team's releases and every other team's failures.
 *
 * Null keeps the old meaning exactly — the whole registry — so nothing an
 * existing installation configured changes, and an endpoint is narrowed by an
 * act rather than by an upgrade.
 *
 * `cascadeOnDelete` rather than `nullOnDelete`: a webhook scoped to a
 * repository that has been deleted is not an installation-wide webhook, and
 * quietly widening one to the whole registry is precisely the disclosure this
 * column exists to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outgoing_webhooks', function (Blueprint $table) {
            $table->foreignId('repository_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('outgoing_webhooks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('repository_id');
        });
    }
};
