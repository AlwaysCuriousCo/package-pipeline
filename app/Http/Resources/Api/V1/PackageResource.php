<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Package;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One package, as the API describes it.
 *
 * Every field is named here rather than serialised off the model, which is the
 * only way a stable contract and a moving schema can coexist — and the only
 * way two of this model's columns stay where they belong. `token` is the VCS
 * credential the registry authenticates to GitHub with and `webhook_secret` is
 * what proves a delivery came from the provider; both are encrypted casts, so
 * a `toArray()` here would have decrypted them on the way out.
 *
 * @mixin Package
 */
class PackageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            // The VCS repository this package syncs from — null for one
            // published by artifact upload, which has no source at all. Not to
            // be confused with `repository` below, which is the Composer
            // repository serving it; the model carries the same collision and
            // names the relation composerRepository() for the same reason.
            'url' => $this->repository,
            'provider' => $this->repository === null ? null : $this->provider()->value,
            'latest_version' => $this->latest_version,
            'abandoned' => $this->abandoned,
            'replacement_package' => $this->replacement_package,
            'downloads' => $this->total_downloads,
            'repository' => new RepositoryResource($this->whenLoaded('composerRepository')),
            'sync' => $this->syncState(),
            'versions_count' => $this->whenCounted('versions'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * What the last sync did and whether pushes will trigger the next one.
     *
     * Only columns, on purpose. Whether a batch is *still running* costs a
     * lookup per package (Package::syncBatch), and webhook coverage can cost a
     * request to GitHub — neither belongs in a listing that renders a page of
     * these. The detail resource, which renders exactly one, answers the first.
     *
     * @return array<string, mixed>
     */
    protected function syncState(): array
    {
        return [
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            // The synchronizer records partial failures and refused renames
            // here too, so this is "what the last sync had to say", not only
            // "why it died".
            'error' => $this->sync_error,
            'webhook_enabled' => $this->webhook_enabled,
        ];
    }
}
