<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a user put away the dashboard's Getting started card.
 *
 * Per user rather than per browser, like `email_notifications`: someone who
 * has dismissed it once should not meet it again on a second machine. Null for
 * everybody to begin with, and the card also leaves on its own for everyone
 * once the registry has served its first download.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('getting_started_dismissed_at')->nullable()->after('email_notifications');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('getting_started_dismissed_at');
        });
    }
};
