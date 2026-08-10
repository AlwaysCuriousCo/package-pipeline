<?php

namespace App\Models;

use App\Support\ActingCredential;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Spatie\Activitylog\Models\Activity as BaseActivity;

/**
 * The audit trail, given a retention policy `model:prune` can enforce.
 *
 * The table is written by the LogsActivity trait on the models being audited
 * and only ever read afterwards — nothing updates or deletes a row — so it
 * grows for as long as the registry runs. That is the same mistake the
 * notifications table made before it was given a policy, and on a busier
 * table: a sync that corrects one package's description writes a row.
 *
 * @see Notification for the same arrangement over notifications.
 */
class Activity extends BaseActivity
{
    use MassPrunable;

    protected static function booted(): void
    {
        // Stamped here rather than by each writer, because there are several —
        // the attribute diff, LogRoleChange, AuditedBelongsToMany — and a
        // credential that only some of them recorded would be a gap shaped
        // exactly like the one it exists to close.
        static::creating(function (self $activity): void {
            $credential = app(ActingCredential::class)->describe();

            if ($credential !== null) {
                $activity->properties = collect($activity->properties ?? [])->put('credential', $credential);
            }
        });
    }

    /**
     * How long an entry is kept.
     *
     * Two years, because the question an audit trail answers is asked late:
     * "who rolled that token" and "when did this account get that role" come
     * up during an incident, months after the change. It is deliberately far
     * longer than the notifications table's ninety days, which holds things
     * that are only interesting while they are fresh.
     */
    private const RETENTION_DAYS = 730;

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
