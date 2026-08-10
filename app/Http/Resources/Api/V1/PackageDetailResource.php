<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Package;
use Illuminate\Http\Request;

/**
 * One package with everything a caller asked for it by name would want: its
 * versions, and whether a sync is working through them right now.
 *
 * Both are per-package queries, which is the whole reason they are not on the
 * listing resource this extends.
 *
 * @mixin Package
 */
class PackageDetailResource extends PackageResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'versions' => PackageVersionResource::collection($this->whenLoaded('versions')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function syncState(): array
    {
        return [
            ...parent::syncState(),
            // What a CI script polls after triggering a sync: true until the
            // batch importing this package's versions has finished.
            'running' => $this->resource->syncRunning(),
        ];
    }
}
