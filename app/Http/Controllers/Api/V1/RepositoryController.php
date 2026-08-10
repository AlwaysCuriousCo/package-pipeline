<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\RepositoryResource;
use App\Models\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The registries this installation serves, as a provisioning script sees them.
 *
 * Read-only. Creating a repository decides a URL every consuming project has
 * to be told about and an access rule for everything inside it; that is an
 * operator's decision made once, in the panel, not something a CI credential
 * should be able to do on its own.
 */
class RepositoryController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $repositories = $this->repositories($request)
            ->withCount('packages')
            ->orderBy('name')
            ->paginate($this->perPage($request));

        return RepositoryResource::collection($repositories);
    }

    public function show(Request $request, string $repository): RepositoryResource
    {
        $record = $this->repositories($request)
            ->withCount('packages')
            ->whereKey($repository)
            ->first();

        abort_unless($record instanceof Repository, 404, 'No such Composer repository.');

        return new RepositoryResource($record);
    }
}
