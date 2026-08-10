<?php

namespace App\Enums;

use App\Http\Middleware\AuthenticateApi;
use App\Http\Middleware\AuthenticateComposer;
use Filament\Support\Contracts\HasLabel;

/**
 * What an access token may do.
 *
 * Two families, deliberately disjoint. The `repository:*` abilities govern the
 * Composer protocol — resolving metadata, downloading dists, publishing an
 * artifact — which is what a consuming project's composer.json and a release
 * pipeline present. The `api:*` abilities govern the management API, which
 * describes and administers the registry rather than serving it.
 *
 * Keeping them apart is the point: a token pasted into every developer's
 * `auth.json` so `composer install` works must not, by the same act, be able to
 * enumerate the registry's administration or delete a package out from under
 * the projects that depend on it. Nothing here is implied by anything else —
 * each endpoint names the one ability it needs, exactly as the upload endpoint
 * has always required `repository:write` without accepting `repository:read`.
 *
 * @see AuthenticateComposer
 * @see AuthenticateApi
 */
enum TokenAbility: string implements HasLabel
{
    case RepositoryRead = 'repository:read';
    case RepositoryWrite = 'repository:write';
    case ApiRead = 'api:read';
    case ApiWrite = 'api:write';
    case ApiDelete = 'api:delete';

    public function getLabel(): string
    {
        return match ($this) {
            self::RepositoryRead => 'Composer read — install packages',
            self::RepositoryWrite => 'Composer write — publish artifact uploads',
            self::ApiRead => 'API read — list packages, versions and repositories',
            self::ApiWrite => 'API write — create packages and trigger syncs',
            self::ApiDelete => 'API delete — delete packages',
        };
    }
}
