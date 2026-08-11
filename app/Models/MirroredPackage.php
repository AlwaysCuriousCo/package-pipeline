<?php

namespace App\Models;

use Database\Factories\MirroredPackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One upstream document this registry has cached — or one name it has learned
 * the upstream does not have.
 *
 * Deliberately not a Package. A Package is something this registry publishes:
 * it has an owner, a source, a sync history, grants pointed at it and a row in
 * the panel's package list. A mirrored document is a copy of somebody else's
 * answer, held so the next `composer install` does not have to ask again, and
 * throwing every one away would cost nothing but a re-fetch. Keeping the two
 * in one table would put every transitive dependency of every consuming
 * project into the registry's own inventory.
 *
 * @see docs/mirroring.md
 */
#[Fillable(['upstream_id', 'name', 'is_dev', 'payload', 'digest', 'upstream_etag', 'upstream_last_modified', 'fetched_at', 'changed_at', 'used_at'])]
class MirroredPackage extends Model
{
    /** @use HasFactory<MirroredPackageFactory> */
    use HasFactory;

    /**
     * How stale `used_at` may get before it is written again.
     *
     * Retention is measured in days, so recording every single hit would buy
     * nothing and cost a write on the hottest endpoint this app has — one per
     * package per `composer update`, per consuming project. An hour's
     * resolution is far finer than any retention window anyone would set.
     */
    private const USE_RESOLUTION_MINUTES = 60;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_dev' => 'boolean',
            'fetched_at' => 'immutable_datetime',
            'changed_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Upstream, $this>
     */
    public function upstream(): BelongsTo
    {
        return $this->belongsTo(Upstream::class);
    }

    /**
     * Whether the upstream actually has this package.
     *
     * A row with no payload is the negative cache: the upstream was asked and
     * said no, and that answer is worth remembering for a little while.
     */
    public function found(): bool
    {
        return $this->payload !== null;
    }

    /**
     * Whether this can be served without asking the upstream anything.
     *
     * A missing package expires far sooner than a present one, and the two
     * windows are answering different questions. A cached document going an
     * hour stale means a release published in the last hour is not visible
     * yet — which is well inside how long a new version takes to propagate
     * through Composer's own caches anyway. A cached *absence* going stale
     * means a package somebody just published is still unreachable, and that
     * somebody is usually the person waiting on it. It cannot be zero either:
     * a typo in a composer.json would then cost an upstream round trip on
     * every resolve, forever.
     */
    public function isFresh(): bool
    {
        $minutes = (int) config($this->found()
            ? 'registry.mirror.metadata_ttl_minutes'
            : 'registry.mirror.missing_ttl_minutes');

        $seconds = $minutes * 60;

        return $this->fetched_at->gt(now()->subSeconds($seconds - $this->jitter($seconds)));
    }

    /**
     * A little off the front of the window, so a whole cache does not lapse at
     * once.
     *
     * A cold `composer update` fills this table with a few hundred rows inside
     * a second or two, all with the same `fetched_at`. A plain comparison then
     * expires all of them in the same second an hour later, and the next build
     * revalidates the entire dependency graph in one burst — against one
     * upstream, from however many workers are free.
     *
     * Cut from the name rather than drawn at random, because a row has to give
     * the same answer every time it is asked inside one request: a random
     * offset would let the same document be fresh when it is looked up and
     * stale when it is checked again, which is a bug rather than a spread.
     * A tenth of the window is enough to turn a burst into a trickle and far
     * too little to notice as staleness.
     */
    private function jitter(int $seconds): int
    {
        $spread = intdiv($seconds, 10);

        return $spread < 1 ? 0 : crc32((string) $this->name.($this->is_dev ? '~dev' : '')) % $spread;
    }

    /**
     * Record that this was served, for retention to read later.
     */
    public function markUsed(): void
    {
        if ($this->used_at->gt(now()->subMinutes(self::USE_RESOLUTION_MINUTES))) {
            return;
        }

        // Bookkeeping, not an edit: `updated_at` keeps meaning "when what we
        // hold last changed", which is what makes it safe to leave out of the
        // validators clients revalidate against.
        static::withoutTimestamps(fn () => $this->forceFill(['used_at' => now()])->saveQuietly());
    }
}
