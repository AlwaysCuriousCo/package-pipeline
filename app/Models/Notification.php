<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The notifications table, given a retention policy `model:prune` can enforce.
 *
 * Nothing reads or writes through this class: Notifiable and the panel's bell
 * both use the framework's own DatabaseNotification. It exists because the
 * table has no other owner — AdminNotifier inserts a row per admin per event,
 * and marking one read is the only thing that ever happens to it afterwards.
 */
class Notification extends DatabaseNotification
{
    use MassPrunable;

    /**
     * How long a notification the admin has already seen is kept.
     */
    private const READ_RETENTION_DAYS = 30;

    /**
     * How long an unread one is kept. Longer, because an unread sync failure
     * is the one notification worth finding late — but not forever: a bell
     * badge counting hundreds of stale failures is one nobody reads at all.
     */
    private const RETENTION_DAYS = 90;

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where(fn (Builder $query) => $query
                ->whereNotNull('read_at')
                ->where('read_at', '<', now()->subDays(self::READ_RETENTION_DAYS)))
            ->orWhere('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
