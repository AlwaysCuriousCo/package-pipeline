<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PackageVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One stored version of a package.
 *
 * Deliberately not the version's `metadata`, which is the ref's whole
 * composer.json — requirements, autoload maps, scripts. Composer's own /p2
 * endpoint serves that, in the minified form Composer expects, and a second
 * rendering of it here would be a second thing to keep true. What a CI script
 * asks this endpoint is "is 1.2.3 published, and is it the artifact I built",
 * which is the reference and the shasum.
 *
 * @mixin PackageVersion
 */
class PackageVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'version' => $this->version,
            'is_dev' => $this->is_dev,
            // The commit sha a synced version was built from, or the zip's own
            // hash for an uploaded artifact — either way, what the dist URL is
            // keyed by.
            'reference' => $this->reference,
            'shasum' => $this->shasum,
            'released_at' => $this->released_at?->toIso8601String(),
        ];
    }
}
