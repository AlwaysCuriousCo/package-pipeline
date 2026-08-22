<?php

use App\Enums\GrantSource;
use App\Services\Billing\EntitlementProjector;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every grant an author: a person, or the entitlement projector.
 *
 * Subscriptions grant access by writing rows into these same four pivots —
 * that is the design decision that keeps billing off the Composer hot path,
 * because Package::scopeVisibleTo() and User::packageGrants() keep reading
 * exactly the tables they already read. But two writers on one table need a
 * boundary, and this column is it: the projector owns every 'subscription'
 * row and never touches a 'manual' one, and the panel's grant editors work
 * the other way around. Cancelling a subscription cannot revoke a grant an
 * administrator made by name; an administrator tidying a grant list cannot
 * silently un-sell something that was paid for.
 *
 * The unique index widens to include the source, because the same grant can
 * now legitimately exist twice — given by hand and sold — and each copy must
 * be withdrawable on its own. The visibility union was documented as
 * duplicate-tolerant from the day teams landed, so a doubled id changes no
 * answer anywhere.
 *
 * The reader-direction indexes from 2026_08_10_220000 are untouched: they
 * serve membership tests, which do not care who wrote the row.
 *
 * @see GrantSource
 * @see EntitlementProjector
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The new unique must exist before the old one is dropped: MySQL backs
        // the leftmost-column FK with the old index and errors (1553) if no
        // replacement index covers it. The hasColumn guard makes the migration
        // rerunnable after a partial failure, since each ALTER auto-commits.
        foreach ($this->pivots() as $pivot => $cols) {
            Schema::table($pivot, function (Blueprint $table) use ($pivot, $cols) {
                if (! Schema::hasColumn($pivot, 'source')) {
                    $table->string('source', 32)->default('manual');
                }
                $table->unique([...$cols, 'source']);
                $table->dropUnique($cols);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Narrowing the unique back would fail on a table where a grant
        // exists under both sources; drop the projector's rows first, which
        // is also the honest meaning of rolling this feature back.
        foreach ($this->pivots() as $pivot => $cols) {
            DB::table($pivot)->where('source', 'subscription')->delete();

            // Same FK-backing rule as up(): recreate the narrow unique before
            // dropping the wide one.
            Schema::table($pivot, function (Blueprint $table) use ($cols) {
                $table->unique($cols);
                $table->dropUnique([...$cols, 'source']);
                $table->dropColumn('source');
            });
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function pivots(): array
    {
        return [
            'package_user' => ['package_id', 'user_id'],
            'repository_user' => ['repository_id', 'user_id'],
            'package_team' => ['package_id', 'team_id'],
            'repository_team' => ['repository_id', 'team_id'],
        ];
    }
};
