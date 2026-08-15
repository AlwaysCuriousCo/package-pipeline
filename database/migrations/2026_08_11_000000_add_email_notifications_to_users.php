<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each panel user's own answer to "email me about this too".
 *
 * Defaulted on rather than off, which looks like the wrong way round for a
 * feature that is meant to be quiet by default. It is not, because this column
 * is not the switch: `MAIL_ADMIN_NOTIFICATIONS` is, it ships false, and nothing
 * here is read at all while it stays that way. An installation that turns the
 * environment setting on has said it wants these emails, and a column
 * defaulting to false would mean the change appeared to do nothing until every
 * user had gone and found the toggle — the sort of half-working state that gets
 * reported as a bug.
 *
 * So the environment decides whether email happens; this decides who is spared
 * it. Existing rows pick up the default and are opted in on the same terms as
 * new ones, which is the same statement made once rather than twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('email_notifications')->default(true)->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_notifications');
        });
    }
};
