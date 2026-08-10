<?php

namespace App\Models;

use App\Models\Concerns\LogsAuditableChanges;
use Database\Factories\PackageAdvisoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A known vulnerability in one version range of one package.
 *
 * Published through the repository's security-advisories endpoint, which is
 * what makes `composer audit` — and the audit Composer 2.9+ runs during
 * `composer update` — say anything at all about a package served from here.
 *
 * Rows are recorded by hand today. The `source` column is what lets an
 * importer for an external feed share the table later without having to tell
 * the two apart by guesswork.
 */
#[Fillable(['advisory_id', 'source', 'title', 'affected_versions', 'cve', 'link', 'severity', 'reported_at'])]
class PackageAdvisory extends Model
{
    /** @use HasFactory<PackageAdvisoryFactory> */
    use HasFactory, LogsAuditableChanges;

    /**
     * What a consumer's `composer audit` is told, which is a claim about a
     * package's safety and belongs on the record as one.
     *
     * @return list<string>
     */
    protected function auditedAttributes(): array
    {
        return ['package_id', 'advisory_id', 'title', 'affected_versions', 'severity', 'cve', 'link', 'reported_at', 'source'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reported_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        // An advisory is unusable to a consumer without an id — it is the only
        // handle `composer audit --ignore` accepts — but asking an admin to
        // invent one is asking them to invent a unique string. A generated id
        // in Packagist's readable shape is the default; a row imported from a
        // feed keeps whatever the feed calls it.
        static::creating(function (self $advisory): void {
            if (blank($advisory->advisory_id)) {
                $advisory->advisory_id = self::generateAdvisoryId();
            }
        });
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * This advisory as Composer's security-advisories API describes one.
     *
     * Every key here is load-bearing. `composer audit` asks for *full*
     * advisories, and Composer decides an advisory is full by the presence of
     * `title`, `sources` and `reportedAt` together — a response missing any of
     * the three is rejected with a RuntimeException rather than degraded, so
     * the three are never conditional below. The shape is Composer's
     * Advisory\PartialSecurityAdvisory::create(), read from composer/composer
     * — not a dependency here, hence the name spelled out rather than linked.
     *
     * @return array<string, mixed>
     */
    public function toComposerAdvisory(string $packageName, Repository $repository): array
    {
        return [
            'advisoryId' => $this->advisory_id,
            'packageName' => $packageName,
            'affectedVersions' => $this->affected_versions,
            'title' => $this->title,
            // Composer matches an ignore-list entry against `remoteId`, so the
            // id has to appear here as well as above — the top-level
            // `advisoryId` is not what that lookup reads.
            'sources' => [[
                'name' => $this->source ?? config('app.name'),
                'remoteId' => $this->advisory_id,
            ]],
            'reportedAt' => $this->reported_at->toIso8601String(),
            // Composer reads these three as optional and tolerates nulls, so
            // they are sent as stored rather than omitted — a consumer diffing
            // two responses should see a cleared CVE as cleared.
            'cve' => $this->cve,
            'link' => $this->link,
            'severity' => $this->severity,
            // Which registry mount the advisory was served from. Composer
            // ignores it; Packagist sends it, and a project pulling from
            // several private repositories has no other way to tell which one
            // reported a given advisory.
            'composerRepository' => $repository->url(),
        ];
    }

    /**
     * A registry-unique identifier, shaped like the PKSA-… ids Packagist
     * issues so it reads as an advisory id wherever Composer prints it.
     *
     * Random rather than sequential: the id travels to every consumer that
     * audits, and a counter would tell each of them how many advisories this
     * registry has ever recorded.
     */
    private static function generateAdvisoryId(): string
    {
        do {
            $id = sprintf('PPSA-%04d-%04d-%04d', random_int(0, 9999), random_int(0, 9999), random_int(0, 9999));
        } while (static::query()->where('advisory_id', $id)->exists());

        return $id;
    }
}
