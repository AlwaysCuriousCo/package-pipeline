<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\Repository;
use App\Models\Token;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * What every v1 endpoint shares: the credential it authenticated with, and the
 * two queries that are the only legal way to reach a row through this API.
 *
 * Nothing here fetches a package or a repository except through visibleTo(),
 * the same scope the Composer endpoints and the panel narrow by. A caller that
 * may not see a record is answered 404 rather than 403, because "you may not
 * see this" and "this does not exist" have to be indistinguishable — a 403
 * would confirm the name to a token that could not have fetched it.
 *
 * @see Package::scopeVisibleTo()
 */
abstract class ApiController extends Controller
{
    /**
     * The token this request authenticated with, put there by the middleware
     * that will not let an unauthenticated request reach a controller.
     */
    protected function token(Request $request): Token
    {
        /** @var Token */
        return $request->attributes->get('apiToken');
    }

    /**
     * @return Builder<Package>
     */
    protected function packages(Request $request): Builder
    {
        return Package::query()->visibleTo($this->token($request));
    }

    /**
     * @return Builder<Repository>
     */
    protected function repositories(Request $request): Builder
    {
        return Repository::query()->visibleTo($this->token($request));
    }

    /**
     * How many records a listing returns per page, within a ceiling the caller
     * does not get to raise: the point of paginating is that one request never
     * renders the whole registry.
     */
    protected function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 25), 1), 100);
    }
}
