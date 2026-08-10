<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Package;
use App\Models\Token;
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
            // Where in that repository the package lives; empty for the root,
            // which is what all but a monorepo's packages are.
            'subdirectory' => $this->subdirectory,
            'provider' => $this->repository === null ? null : $this->provider()->value,
            'latest_version' => $this->latest_version,
            'abandoned' => $this->abandoned,
            'replacement_package' => $this->replacement_package,
            'downloads' => $this->total_downloads,
            'repository' => new RepositoryResource($this->whenLoaded('composerRepository')),
            'sync' => $this->syncState($request),
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
    protected function syncState(Request $request): array
    {
        return [
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
            // The synchronizer records partial failures and refused renames
            // here too, so this is "what the last sync had to say", not only
            // "why it died".
            //
            // And what it had to say is a provider's own words — a URL it could
            // not reach, a host that refused a credential — written from the
            // exception verbatim because an operator reading it in the panel
            // needs the detail. Which is why it is withheld below from a caller
            // that reaches this package only because its repository is public:
            // that is every api:read credential in the registry, and none of
            // them asked to be told the name of an internal host.
            'error' => $this->when($this->reachedByGrant($request), fn (): ?string => $this->sync_error),
            'webhook_enabled' => $this->webhook_enabled,
        ];
    }

    /**
     * Whether the presenting credential reaches this package through a grant of
     * its own rather than through the public branch that admits everybody.
     *
     * Asked as the write question, because they have the same answer and not by
     * accident: a repository being public confers reading and nothing else, so
     * the set of packages a credential holds a grant on is exactly the set it
     * may write to.
     *
     * @see Token::mayWriteTo()
     */
    private function reachedByGrant(Request $request): bool
    {
        $token = $request->attributes->get('apiToken');

        return $token instanceof Token && $token->mayWriteToPackage($this->resource);
    }
}
