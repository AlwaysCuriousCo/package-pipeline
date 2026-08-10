<?php

namespace App\Listeners;

use App\Events\PackageDownloaded;
use App\Models\Download;
use App\Models\Package;
use App\Models\PackageVersion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the download row and bumps the denormalized counters, on the queue
 * so serving the archive never waits on bookkeeping.
 */
class RecordDownload implements ShouldQueue
{
    public function handle(PackageDownloaded $event): void
    {
        // Both rows may have moved on since the archive went out: this runs on
        // the queue, and a sync pruning a branch or an admin deleting a
        // package does not wait for it. Writing the event's ids straight in
        // would violate the foreign keys and fail the job — losing the
        // download and leaving a failed_jobs row behind for every one of them.
        //
        // A deleted package took its whole download history with it, cascaded
        // by the same key, so there is no longer anything to count against.
        if (! Package::query()->whereKey($event->packageId)->exists()) {
            return;
        }

        Download::query()->create([
            'package_id' => $event->packageId,
            // A pruned version leaves the row exactly as deleting the version
            // would have: the version string kept, the key nulled.
            'package_version_id' => $this->versionId($event),
            'version' => $event->version,
            'token_prefix' => $event->tokenPrefix,
            'created_at' => now(),
        ]);

        // Counted without touching either row's updated_at. Eloquent stamps it
        // on an increment by default, but a download changes nothing about
        // what the version *is* — and /p2 derives its Last-Modified and ETag
        // from those timestamps, so the busiest packages in the registry would
        // otherwise invalidate their own metadata on every zip served. The
        // panel's "Last synced" column stops reading as "last downloaded" too.
        Model::withoutTimestampsOn([Package::class, PackageVersion::class], function () use ($event): void {
            Package::query()->whereKey($event->packageId)->increment('total_downloads');
            PackageVersion::query()->whereKey($event->packageVersionId)->increment('total_downloads');
        });
    }

    /**
     * The version the archive came from, or null once it no longer exists.
     */
    private function versionId(PackageDownloaded $event): ?int
    {
        if ($event->packageVersionId === null) {
            return null;
        }

        return PackageVersion::query()->whereKey($event->packageVersionId)->exists()
            ? $event->packageVersionId
            : null;
    }
}
