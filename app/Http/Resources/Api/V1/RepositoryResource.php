<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One Composer repository, as the API describes it.
 *
 * @mixin Repository
 */
class RepositoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Null for the default repository rather than an empty string:
            // "served at the root" is a distinct state, not a blank path, and
            // it is what POST /packages accepts to mean the same thing.
            'path' => $this->path,
            'description' => $this->description,
            'public' => $this->public,
            // The URL a consuming project configures. Handing it over saves
            // every caller from rebuilding the /r/{path} convention, and keeps
            // that convention this app's to change.
            'url' => $this->url(),
            'packages_count' => $this->whenCounted('packages'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
