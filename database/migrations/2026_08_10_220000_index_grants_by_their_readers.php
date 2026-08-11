<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes the grant pivots in the direction they are actually read.
 *
 * Every one of these tables had exactly one index — the unique pair that keeps
 * a grant from being recorded twice — and every one of them is queried by the
 * column that pair puts *second*. A composite index is only usable from its
 * leading column, so `unique(team_id, user_id)` answers "who is in this team"
 * and says nothing about "which teams is this user in", which is the question
 * User::packageGrants() and repositoryGrants() ask. Both of those run on the
 * Composer hot path — once per metadata request and once per dist request for
 * every dependency a scoped client resolves — against a scan of the whole
 * pivot each time.
 *
 * The new indexes are the same pair reversed rather than the single column,
 * which costs nothing extra and makes each one covering for the query that
 * needs it: the id the caller is really after (`package_id`, `repository_id`,
 * `team_id`) is already in the index beside the column being filtered, so the
 * lookup never has to visit the row. `package_team` and `repository_team` are
 * reached by a join on `team_id` rather than a filter, and a join drives off
 * an index exactly the same way.
 *
 * `package_advisories` is the same omission in its plainer form: the table has
 * no index on `package_id` at all, because `foreignId()->constrained()` is not
 * one — InnoDB creates one behind every foreign key, so MySQL was covered by
 * accident, while Postgres indexes only the referenced side and SQLite indexes
 * neither. That is the table `/security-advisories` eager-loads, and Composer
 * 2.9 and later run that endpoint inside every `composer update`. See
 * 2026_08_09_140000, which is the same discovery about `downloads`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('team_user', function (Blueprint $table) {
            $table->index(['user_id', 'team_id']);
        });

        Schema::table('package_team', function (Blueprint $table) {
            $table->index(['team_id', 'package_id']);
        });

        Schema::table('repository_team', function (Blueprint $table) {
            $table->index(['team_id', 'repository_id']);
        });

        Schema::table('package_user', function (Blueprint $table) {
            $table->index(['user_id', 'package_id']);
        });

        Schema::table('repository_user', function (Blueprint $table) {
            $table->index(['user_id', 'repository_id']);
        });

        Schema::table('package_advisories', function (Blueprint $table) {
            $table->index('package_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('package_advisories', function (Blueprint $table) {
            $table->dropIndex(['package_id']);
        });

        Schema::table('repository_user', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'repository_id']);
        });

        Schema::table('package_user', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'package_id']);
        });

        Schema::table('repository_team', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'repository_id']);
        });

        Schema::table('package_team', function (Blueprint $table) {
            $table->dropIndex(['team_id', 'package_id']);
        });

        Schema::table('team_user', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'team_id']);
        });
    }
};
