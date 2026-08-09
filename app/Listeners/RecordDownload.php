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
        Download::query()->create([
            'package_id' => $event->packageId,
            'package_version_id' => $event->packageVersionId,
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
}
