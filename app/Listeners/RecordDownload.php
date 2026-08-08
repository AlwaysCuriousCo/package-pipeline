<?php

namespace App\Listeners;

use App\Events\PackageDownloaded;
use App\Models\Download;
use App\Models\Package;
use App\Models\PackageVersion;
use Illuminate\Contracts\Queue\ShouldQueue;

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
            'ip' => $event->ip,
            'token_prefix' => $event->tokenPrefix,
            'created_at' => now(),
        ]);

        Package::query()->whereKey($event->packageId)->increment('total_downloads');
        PackageVersion::query()->whereKey($event->packageVersionId)->increment('total_downloads');
    }
}
