<?php

namespace App\Models;

use Database\Factories\MirroredArchiveFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An upstream release archive this registry has fetched, verified and kept.
 *
 * The row existing is the claim that the bytes on the disk hash to `shasum`,
 * because that is checked before it is written and a mismatch is refused
 * rather than recorded — so serving one never re-hashes megabytes to find out
 * whether it may.
 *
 * @see docs/mirroring.md
 */
class MirroredArchive extends Model
{
    /** @use HasFactory<MirroredArchiveFactory> */
    use HasFactory;

    /**
     * As on MirroredPackage, and for the same reason: this is touched by every
     * download of a popular package and read only by a retention sweep that
     * counts in days.
     */
    private const USE_RESOLUTION_MINUTES = 60;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
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
     * Record that this was served, for retention to read later.
     */
    public function markUsed(): void
    {
        if ($this->used_at->gt(now()->subMinutes(self::USE_RESOLUTION_MINUTES))) {
            return;
        }

        static::withoutTimestamps(fn () => $this->forceFill(['used_at' => now()])->saveQuietly());
    }
}
