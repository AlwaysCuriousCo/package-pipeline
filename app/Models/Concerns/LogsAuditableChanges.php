<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Records who changed a record, and what about it changed.
 *
 * This is a registry for supply-chain artifacts: a rolled token, a widened
 * grant and a deleted package are all things somebody will need to attribute
 * months later, and none of them left a trace before.
 *
 * The trait exists rather than each model configuring LogsActivity itself
 * because of one rule that must hold everywhere: **a secret must never reach
 * the activity log**. Encrypted casts (`token`, `client_secret`,
 * `webhook_secret`), the hashed password, and the sha256 an access token is
 * stored as are all ordinary attributes as far as Eloquent — and therefore
 * Spatie — is concerned, and an attribute diff is written to a table whose
 * whole purpose is to be read later, by more people, for longer.
 *
 * So each model states an allowlist (`auditedAttributes()`), and this trait
 * then filters that allowlist a second time against everything that looks
 * like a secret. Two independent gates, because one of them is a hand-written
 * list and hand-written lists go stale the day somebody adds a column.
 */
trait LogsAuditableChanges
{
    use LogsActivity;

    /**
     * Attribute names never written to the log, whatever a model asks for.
     *
     * Matched exactly, on the attribute name. The cast check below catches
     * the encrypted and hashed columns; this catches the ones stored already
     * digested — `access_tokens.token` holds a sha256 and carries no cast at
     * all, so nothing else would have stopped it.
     */
    private const NEVER_LOGGED = [
        'token', 'password', 'secret', 'client_secret', 'webhook_secret',
        'remember_token', 'api_key', 'private_key',
    ];

    /**
     * The attributes this model considers worth attributing a change to.
     *
     * Stated per model rather than derived from `$fillable`, because the two
     * questions are different: fillable is "what may a form write", audited is
     * "what would somebody investigating an incident need to see". Sync
     * bookkeeping — `last_synced_at`, `sync_error`, `total_downloads` — is
     * deliberately absent: it changes hourly, was written by a worker rather
     * than a person, and would bury the changes that matter.
     *
     * @return list<string>
     */
    abstract protected function auditedAttributes(): array;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('audit')
            ->logOnly($this->loggableAttributes())
            // A save that touched nothing audited writes nothing. This is what
            // keeps the hourly sync from filing a row per package per hour.
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * The model's allowlist, minus anything that looks like a secret.
     *
     * @return list<string>
     */
    protected function loggableAttributes(): array
    {
        $casts = $this->getCasts();

        return array_values(array_filter(
            $this->auditedAttributes(),
            function (string $attribute) use ($casts): bool {
                if (in_array($attribute, self::NEVER_LOGGED, true)) {
                    return false;
                }

                $cast = $casts[$attribute] ?? '';

                // `encrypted`, `encrypted:array`, `encrypted:collection` — and
                // `hashed`, which is not reversible but is still a credential
                // whose value has no business being copied around.
                return ! str_starts_with($cast, 'encrypted') && $cast !== 'hashed';
            },
        ));
    }
}
